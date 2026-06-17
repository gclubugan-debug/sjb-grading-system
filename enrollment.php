<?php
session_start();
require_once 'audit_helper.php';
require_once 'db.php';
require_once 'helpers.php';

require_login(['admin']);

$message = "";
$tab = $_GET['tab'] ?? 'assign';

// Make sure management tables exist.
// Sections already use the sections table. Courses will use the same setup style.
$conn->query(
    "CREATE TABLE IF NOT EXISTS courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_name VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === "assign_subject") {
        $student_id = intval($_POST['student_id']);
        $subject_id = intval($_POST['subject_id']);
        $term = $_POST['academic_term'];
        $sy = trim($_POST['school_year']);

        $stmt = $conn->prepare(
            "INSERT IGNORE INTO student_subjects
            (student_id, subject_id, academic_term, school_year)
            VALUES (?, ?, ?, ?)"
        );

        $stmt->bind_param("iiss", $student_id, $subject_id, $term, $sy);

        $message = $stmt->execute()
            ? "Subject assigned to student."
            : "Error: " . $stmt->error;

        $tab = "assign";
    }

    if ($action === "remove_subject") {
        $id = intval($_POST['student_subject_id']);

        $stmt = $conn->prepare("DELETE FROM student_subjects WHERE id=?");
        $stmt->bind_param("i", $id);

        $message = $stmt->execute()
            ? "Assigned subject removed."
            : "Error: " . $stmt->error;

        $tab = "assign";
    }

    if ($action === "create_template") {
        $template_name = trim($_POST['template_name']);
        $academic_term = $_POST['academic_term'];
        $school_year = trim($_POST['school_year']);

        $stmt = $conn->prepare(
            "INSERT INTO subject_templates
            (template_name, academic_term, school_year)
            VALUES (?, ?, ?)"
        );

        $stmt->bind_param("sss", $template_name, $academic_term, $school_year);

        $message = $stmt->execute()
            ? "Template created successfully."
            : "Error: " . $stmt->error;

        $tab = "templates";
    }

    if ($action === "delete_template") {
        $template_id = intval($_POST['template_id'] ?? 0);

        if ($template_id > 0) {
            $stmt = $conn->prepare("DELETE FROM subject_templates WHERE id=?");
            $stmt->bind_param("i", $template_id);

            if ($stmt->execute()) {
                $message = "Template deleted successfully.";

                if (function_exists('log_audit')) {
                    log_audit($conn, "Delete Subject Template", "Deleted subject template ID $template_id.");
                }
            } else {
                $message = "Error: " . $stmt->error;
            }
        } else {
            $message = "Invalid template selected.";
        }

        $tab = "templates";
    }

    if ($action === "remove_template_subject") {
        $id = intval($_POST['template_item_id']);

        $stmt = $conn->prepare("DELETE FROM subject_template_items WHERE id=?");
        $stmt->bind_param("i", $id);

        $message = $stmt->execute()
            ? "Subject removed from template."
            : "Error: " . $stmt->error;

        $tab = "templates";
    }

    if ($action === "assign_template_to_student") {
        $template_id = intval($_POST['template_id']);
        $student_id = intval($_POST['student_id']);

        $template = $conn->prepare(
            "SELECT academic_term, school_year
            FROM subject_templates
            WHERE id=?"
        );

        $template->bind_param("i", $template_id);
        $template->execute();
        $template_data = $template->get_result()->fetch_assoc();

        if ($template_data) {
            $items = $conn->prepare(
                "SELECT subject_id
                FROM subject_template_items
                WHERE template_id=?"
            );

            $items->bind_param("i", $template_id);
            $items->execute();
            $result = $items->get_result();

            while ($row = $result->fetch_assoc()) {
                $insert = $conn->prepare(
                    "INSERT IGNORE INTO student_subjects
                    (student_id, subject_id, academic_term, school_year)
                    VALUES (?, ?, ?, ?)"
                );

                $insert->bind_param(
                    "iiss",
                    $student_id,
                    $row['subject_id'],
                    $template_data['academic_term'],
                    $template_data['school_year']
                );

                $insert->execute();
            }

            $message = "Template assigned to student successfully.";
        }

        $tab = "templates";
    }

    if ($action === "add_section") {
        $section_name = strtoupper(trim($_POST['section_name'] ?? ''));

        if ($section_name === '') {
            $message = "Section name is required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO sections (section_name) VALUES (?)");
            $stmt->bind_param("s", $section_name);

            if ($stmt->execute()) {
                $message = "Section added successfully.";

                if (function_exists('log_audit')) {
                    log_audit($conn, "Add Section", "Added section $section_name.");
                }
            } else {
                $message = "Section already exists or could not be added.";
            }
        }

        $tab = "sections";
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
                $message = "Cannot delete this section because it is already used by students or pending requests.";
            } else {
                $stmt = $conn->prepare("DELETE FROM sections WHERE id=?");
                $stmt->bind_param("i", $section_id);

                if ($stmt->execute()) {
                    $message = "Section deleted successfully.";

                    if (function_exists('log_audit')) {
                        log_audit($conn, "Delete Section", "Deleted section $section_name.");
                    }
                } else {
                    $message = "Error: " . $stmt->error;
                }
            }
        } else {
            $message = "Invalid section selected.";
        }

        $tab = "sections";
    }

    if ($action === "add_course") {
        $course_name = strtoupper(trim($_POST['course_name'] ?? ''));

        if ($course_name === '') {
            $message = "Course name is required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO courses (course_name) VALUES (?)");
            $stmt->bind_param("s", $course_name);

            if ($stmt->execute()) {
                $message = "Course added successfully.";

                if (function_exists('log_audit')) {
                    log_audit($conn, "Add Course", "Added course $course_name.");
                }
            } else {
                $message = "Course already exists or could not be added.";
            }
        }

        $tab = "courses";
    }

    if ($action === "delete_course") {
        $course_id = intval($_POST['course_id'] ?? 0);

        $course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE id=?");
        $course_stmt->bind_param("i", $course_id);
        $course_stmt->execute();
        $course_data = $course_stmt->get_result()->fetch_assoc();

        if ($course_data) {
            $course_name = $course_data['course_name'];

            $check_students = $conn->prepare("SELECT COUNT(*) AS total FROM students WHERE course=?");
            $check_students->bind_param("s", $course_name);
            $check_students->execute();
            $student_count = $check_students->get_result()->fetch_assoc()['total'] ?? 0;

            $check_pending = $conn->prepare("SELECT COUNT(*) AS total FROM pending_requests WHERE course=? AND status='pending'");
            $check_pending->bind_param("s", $course_name);
            $check_pending->execute();
            $pending_count = $check_pending->get_result()->fetch_assoc()['total'] ?? 0;

            if ($student_count > 0 || $pending_count > 0) {
                $message = "Cannot delete this course because it is already used by students or pending requests.";
            } else {
                $stmt = $conn->prepare("DELETE FROM courses WHERE id=?");
                $stmt->bind_param("i", $course_id);

                if ($stmt->execute()) {
                    $message = "Course deleted successfully.";

                    if (function_exists('log_audit')) {
                        log_audit($conn, "Delete Course", "Deleted course $course_name.");
                    }
                } else {
                    $message = "Error: " . $stmt->error;
                }
            }
        } else {
            $message = "Invalid course selected.";
        }

        $tab = "courses";
    }

    if ($action === "assign_template_to_section") {
        $template_id = intval($_POST['template_id']);
        $course = trim($_POST['course']);
        $section = trim($_POST['section']);

        $template = $conn->prepare(
            "SELECT academic_term, school_year
            FROM subject_templates
            WHERE id=?"
        );

        $template->bind_param("i", $template_id);
        $template->execute();
        $template_data = $template->get_result()->fetch_assoc();

        if ($template_data) {
            $students_result = $conn->prepare(
                "SELECT id
                FROM students
                WHERE course=?
                AND section=?
                AND (status='active' OR status IS NULL)"
            );

            $students_result->bind_param("ss", $course, $section);
            $students_result->execute();
            $student_rows = $students_result->get_result();

            while ($student = $student_rows->fetch_assoc()) {
                $items = $conn->prepare(
                    "SELECT subject_id
                    FROM subject_template_items
                    WHERE template_id=?"
                );

                $items->bind_param("i", $template_id);
                $items->execute();
                $item_rows = $items->get_result();

                while ($row = $item_rows->fetch_assoc()) {
                    $insert = $conn->prepare(
                        "INSERT IGNORE INTO student_subjects
                        (student_id, subject_id, academic_term, school_year)
                        VALUES (?, ?, ?, ?)"
                    );

                    $insert->bind_param(
                        "iiss",
                        $student['id'],
                        $row['subject_id'],
                        $template_data['academic_term'],
                        $template_data['school_year']
                    );

                    $insert->execute();
                }
            }

            $message = "Template assigned to section successfully.";
        }

        $tab = "templates";
    }
}

$students = $conn->query(
    "SELECT *
     FROM students
     ORDER BY course, section, name"
);

$students2 = $conn->query(
    "SELECT *
     FROM students
     ORDER BY course, section, name"
);

$subjects = $conn->query(
    "SELECT *
     FROM subjects
     ORDER BY subject_code"
);

$templates = $conn->query(
    "SELECT *
     FROM subject_templates
     ORDER BY template_name"
);

$templates2 = $conn->query(
    "SELECT *
     FROM subject_templates
     ORDER BY template_name"
);

$templates3 = $conn->query(
    "SELECT *
     FROM subject_templates
     ORDER BY template_name"
);

$courses = $conn->query(
    "SELECT course_name AS course
     FROM courses
     ORDER BY course_name"
);

$course_management = $conn->query(
    "SELECT *
     FROM courses
     ORDER BY course_name"
);

$sections = $conn->query(
    "SELECT section_name
     FROM sections
     ORDER BY section_name"
);

$section_management = $conn->query(
    "SELECT *
     FROM sections
     ORDER BY section_name"
);

$enrolled = $conn->query(
    "SELECT
        ss.id,
        st.student_no,
        st.name,
        st.course,
        st.section,
        sub.subject_code,
        sub.subject_name,
        sub.units,
        ss.academic_term,
        ss.school_year
     FROM student_subjects ss
     JOIN students st ON ss.student_id = st.id
     JOIN subjects sub ON ss.subject_id = sub.id
     ORDER BY ss.academic_term, st.course, st.section, st.name"
);

$template_cards = $conn->query(
    "SELECT
        st.id,
        st.template_name,
        st.academic_term,
        st.school_year,
        COUNT(sti.id) AS subject_count
     FROM subject_templates st
     LEFT JOIN subject_template_items sti ON st.id = sti.template_id
     GROUP BY st.id, st.template_name, st.academic_term, st.school_year
     ORDER BY st.template_name"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Subjects</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
  
<?php nav_bar(); ?>

<div class="container">

    <h1>Student Subjects</h1>

    <?php if ($message): ?>
        <div class="alert alert-success">
            <?= safe($message) ?>
        </div>
    <?php endif; ?>

    <div class="quick-buttons no-print">
        <a class="btn <?= $tab === 'assign' ? 'btn-yellow' : 'btn-gray' ?>" href="enrollment.php?tab=assign">
            Assign Subject
        </a>
        <a class="btn <?= $tab === 'templates' ? 'btn-yellow' : 'btn-gray' ?>" href="enrollment.php?tab=templates">
            Subject Templates
        </a>
        <a class="btn <?= $tab === 'sections' ? 'btn-yellow' : 'btn-gray' ?>" href="enrollment.php?tab=sections">
            Manage Sections
        </a>
        <a class="btn <?= $tab === 'courses' ? 'btn-yellow' : 'btn-gray' ?>" href="enrollment.php?tab=courses">
            Manage Courses
        </a>
    </div>

    <br>

    <?php if ($tab === 'assign'): ?>

        <form method="POST" class="card grid grid-4">

            <input type="hidden" name="action" value="assign_subject">

            <select name="student_id" required>
                <option value="">Select Student</option>
                <?php while ($s = $students->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>">
                        <?= safe($s['student_no']) ?> - <?= safe($s['name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="subject_id" required>
                <option value="">Select Subject</option>
                <?php while ($sub = $subjects->fetch_assoc()): ?>
                    <option value="<?= $sub['id'] ?>">
                        <?= safe($sub['subject_code']) ?> - <?= safe($sub['subject_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="academic_term">
                <option>1st Term</option>
                <option>2nd Term</option>
                <option>3rd Term</option>
            </select>

            <input type="text" name="school_year" value="2025-2026">

            <button class="btn btn-yellow">Assign Subject</button>

        </form>

        <br>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Course/Section</th>
                        <th>Term</th>
                        <th>Subject</th>
                        <th>Units</th>
                        <th>School Year</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($enrolled && $enrolled->num_rows > 0): ?>
                    <?php while ($e = $enrolled->fetch_assoc()): ?>
                        <tr>
                            <td><?= safe($e['student_no']) ?> - <?= safe($e['name']) ?></td>
                            <td><?= safe($e['course']) ?> <?= safe($e['section']) ?></td>
                            <td><?= safe($e['academic_term']) ?></td>
                            <td><?= safe($e['subject_code']) ?> - <?= safe($e['subject_name']) ?></td>
                            <td><?= safe($e['units']) ?></td>
                            <td><?= safe($e['school_year']) ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Remove this subject from the student?');">
                                    <input type="hidden" name="action" value="remove_subject">
                                    <input type="hidden" name="student_subject_id" value="<?= $e['id'] ?>">
                                    <button class="btn btn-red">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align:center;">No assigned subjects yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

    <?php if ($tab === 'templates'): ?>

        <style>
            .template-dashboard {
                display: flex;
                justify-content: center;
                margin-bottom: 20px;
            }

            .template-stat {
                background: #111;
                border: 1px solid #facc15;
                border-radius: 14px;
                padding: 18px;
                text-align: center;
                width: 100%;
                max-width: 500px;
            }

            .template-stat h3 {
                color: #facc15;
                margin: 0 0 8px;
            }

            .template-stat .number {
                font-size: 34px;
                font-weight: bold;
            }

            .template-card-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 16px;
                margin-bottom: 20px;
            }

            .template-card {
                background: #111;
                border: 1px solid #facc15;
                border-radius: 16px;
                padding: 18px;
                box-shadow: 0 8px 18px rgba(0,0,0,.25);
            }

            .template-card h3 {
                color: #facc15;
                margin-top: 0;
                margin-bottom: 10px;
                font-size: 22px;
            }

            .template-meta {
                display: grid;
                gap: 8px;
                margin: 14px 0;
            }

            .template-meta div {
                background: #000;
                border-radius: 10px;
                padding: 10px;
            }

            .template-actions {
                display: grid;
                gap: 8px;
                margin-top: 14px;
            }

            .template-section-title {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
                margin-bottom: 12px;
            }

            @media screen and (max-width: 700px) {
                .template-card-grid,
                .template-dashboard {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <?php
            $total_templates_result = $conn->query("SELECT COUNT(*) AS total FROM subject_templates");
            $total_templates_row = $total_templates_result->fetch_assoc();
            $total_templates = $total_templates_row['total'] ?? 0;

        ?>

        <div class="template-dashboard">
            <div class="template-stat">
                <h3>Total Templates</h3>
                <div class="number"><?= safe($total_templates) ?></div>
            </div>

        </div>

        <form method="POST" class="card grid grid-4">
            <input type="hidden" name="action" value="create_template">

            <input
                name="template_name"
                placeholder="Template Name e.g. BSIT 3A First Term"
                required
            >

            <select name="academic_term" required>
                <option value="1st Term">1st Term</option>
                <option value="2nd Term">2nd Term</option>
                <option value="3rd Term">3rd Term</option>
            </select>

            <input name="school_year" value="2025-2026" required>

            <button class="btn btn-yellow">Create Template</button>
        </form>

        <br>

        <div class="template-section-title">
            <h2>Subject Templates</h2>
            <p style="color:#d1d5db; margin:0;">
                Click Manage Subjects to add or remove subjects using search and checkboxes.
            </p>
        </div>

        <div class="template-card-grid">
            <?php if ($template_cards && $template_cards->num_rows > 0): ?>
                <?php while ($ts = $template_cards->fetch_assoc()): ?>
                    <div class="template-card">
                        <h3><?= safe($ts['template_name']) ?></h3>

                        <div class="template-meta">
                            <div><b>Term:</b> <?= safe($ts['academic_term']) ?></div>
                            <div><b>School Year:</b> <?= safe($ts['school_year']) ?></div>
                            <div><b>Subjects Included:</b> <?= safe($ts['subject_count']) ?></div>
                        </div>

                        <div class="template-actions">
                            <a class="btn btn-yellow" href="template_subjects.php?template_id=<?= $ts['id'] ?>">
                                Manage Subjects
                            </a>

                            <form method="POST" onsubmit="return confirm('Delete this template? This will also remove all subjects inside this template.');">
                                <input type="hidden" name="action" value="delete_template">
                                <input type="hidden" name="template_id" value="<?= $ts['id'] ?>">
                                <button class="btn btn-red">Delete Template</button>
                            </form>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="card">
                    <p>No subject templates created yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-2">
            <form method="POST" class="card grid">
                <h2>Assign Template to Student</h2>

                <input type="hidden" name="action" value="assign_template_to_student">

                <select name="template_id" required>
                    <option value="">Select Template</option>
                    <?php while ($t = $templates2->fetch_assoc()): ?>
                        <option value="<?= $t['id'] ?>">
                            <?= safe($t['template_name']) ?> - <?= safe($t['academic_term']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <select name="student_id" required>
                    <option value="">Select Student</option>
                    <?php while ($st = $students2->fetch_assoc()): ?>
                        <option value="<?= $st['id'] ?>">
                            <?= safe($st['student_no']) ?> - <?= safe($st['name']) ?>
                            (<?= safe($st['course']) ?> <?= safe($st['section']) ?>)
                        </option>
                    <?php endwhile; ?>
                </select>

                <button class="btn btn-yellow">Assign to Student</button>
            </form>

            <form method="POST" class="card grid">
                <h2>Assign Template to Section</h2>

                <input type="hidden" name="action" value="assign_template_to_section">

                <select name="template_id" required>
                    <option value="">Select Template</option>
                    <?php while ($t = $templates3->fetch_assoc()): ?>
                        <option value="<?= $t['id'] ?>">
                            <?= safe($t['template_name']) ?> - <?= safe($t['academic_term']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <select name="course" required>
                    <option value="">Select Course</option>
                    <?php while ($c = $courses->fetch_assoc()): ?>
                        <option value="<?= safe($c['course']) ?>">
                            <?= safe($c['course']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <select name="section" required>
                    <option value="">Select Section</option>
                    <?php while ($sec = $sections->fetch_assoc()): ?>
                        <option value="<?= safe($sec['section_name']) ?>">
                            <?= safe($sec['section_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <button class="btn btn-yellow">Assign to Section</button>
            </form>
        </div>



    <?php endif; ?>


    <?php if ($tab === 'sections'): ?>

        <div class="card">
            <h2 style="color:#facc15;">Manage Sections</h2>
            <p style="color:#d1d5db;">
                Add or remove sections here. These sections will appear in the registration dropdown.
            </p>

            <form method="POST" class="grid grid-3">
                <input type="hidden" name="action" value="add_section">
                <input name="section_name" placeholder="Section Name e.g. BSIT-3A" required>
                <button class="btn btn-yellow">Add Section</button>
            </form>
        </div>

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
                        <?php if ($section_management && $section_management->num_rows > 0): ?>
                            <?php while($sec = $section_management->fetch_assoc()): ?>
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

    <?php endif; ?>


    <?php if ($tab === 'courses'): ?>

        <div class="card">
            <h2 style="color:#facc15;">Manage Courses</h2>
            <p style="color:#d1d5db;">
                Add or remove courses here. These courses will appear in the registration dropdown.
            </p>

            <form method="POST" class="grid grid-3">
                <input type="hidden" name="action" value="add_course">
                <input name="course_name" placeholder="Course Name e.g. BSIT" required>
                <button class="btn btn-yellow">Add Course</button>
            </form>
        </div>

        <br>

        <div class="card">
            <h2 style="color:#facc15;">Available Courses</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($course_management && $course_management->num_rows > 0): ?>
                            <?php while($course = $course_management->fetch_assoc()): ?>
                                <tr>
                                    <td data-label="Course"><?= safe($course['course_name']) ?></td>
                                    <td data-label="Created At"><?= safe($course['created_at']) ?></td>
                                    <td data-label="Action">
                                        <form method="POST" onsubmit="return confirm('Delete this course?');">
                                            <input type="hidden" name="action" value="delete_course">
                                            <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                            <button class="btn btn-red">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" style="text-align:center;">No courses added yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>


</div>

</body>
</html>
