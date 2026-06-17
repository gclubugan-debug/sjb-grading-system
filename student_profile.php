<?php

session_start();

require_once 'db.php';
require_once 'helpers.php';

require_login(['admin', 'teacher']);

$student_id = intval($_GET['id'] ?? 0);
$term = $_GET['academic_term'] ?? '1st Term';

$stmt = $conn->prepare(
    "SELECT * FROM students WHERE id = ?"
);

$stmt->bind_param("i", $student_id);
$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student not found.");
}

$stmt = $conn->prepare(
    "SELECT
        ss.*,
        sub.subject_code,
        sub.subject_name,
        sub.units,
        u.name AS teacher_name
    FROM student_subjects ss
    JOIN subjects sub ON ss.subject_id = sub.id
    LEFT JOIN users u ON sub.teacher_id = u.id
    WHERE ss.student_id = ?
    AND ss.academic_term = ?
    ORDER BY sub.subject_code"
);

$stmt->bind_param("is", $student_id, $term);
$stmt->execute();

$subs = $stmt->get_result();

$rows = [];
$units = 0;

while ($r = $subs->fetch_assoc()) {
    $rows[] = $r;
    $units += floatval($r['units']);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Profile</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
  
    
<?php nav_bar(); ?>

<div class="container">

    <h1>Student Profile</h1>

    <div class="card grid grid-2">

        <p>
            <b>Student No.:</b>
            <?= safe($student['student_no']) ?>
        </p>

        <p>
            <b>Name:</b>
            <?= safe($student['name']) ?>
        </p>

        <p>
            <b>Email:</b>
            <?= safe($student['email']) ?>
        </p>

        <p>
            <b>Mobile:</b>
            <?= safe($student['mobile_number']) ?>
        </p>

        <p>
            <b>Course:</b>
            <?= safe($student['course']) ?>
        </p>

        <p>
            <b>Section:</b>
            <?= safe($student['section']) ?>
        </p>

        <p>
            <b>Year:</b>
            <?= safe($student['year_level']) ?>
        </p>

        <p>
            <b>Emergency:</b>
            <?= safe($student['emergency_contact_person']) ?>
            -
            <?= safe($student['emergency_contact_number']) ?>
        </p>

    </div>

    <br>

    <form method="GET" class="card grid grid-3 no-print">

        <input type="hidden" name="id" value="<?= $student_id ?>">

        <select name="academic_term">
            <option>1st Term</option>
            <option <?= $term === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
            <option <?= $term === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
        </select>

        <button class="btn btn-yellow">
            View Term
        </button>

        <button type="button" onclick="window.print()" class="btn btn-blue">
            Print Profile
        </button>

    </form>

    <br>

    <div class="card-yellow">
        <b>Total Units for <?= safe($term) ?>:</b>
        <?= $units ?>
    </div>

    <br>

    <div class="table-wrap">

        <table>

            <thead>
                <tr>
                    <th>Code</th>
                    <th>Subject</th>
                    <th>Units</th>
                    <th>Teacher</th>
                </tr>
            </thead>

            <tbody>

                <?php if ($rows): ?>

                    <?php foreach ($rows as $sub): ?>

                        <tr>

                            <td>
                                <?= safe($sub['subject_code']) ?>
                            </td>

                            <td>
                                <?= safe($sub['subject_name']) ?>
                            </td>

                            <td>
                                <?= safe($sub['units']) ?>
                            </td>

                            <td>
                                <?= safe($sub['teacher_name'] ?? 'Not assigned') ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="4" style="text-align:center;">
                            No subjects assigned for this term.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>