<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_once 'grade_functions.php';
require_login(['student']);

$student_id = intval($_SESSION['student_id']);
$academic_term = $_GET['academic_term'] ?? '1st Term';

$student_stmt = $conn->prepare("SELECT * FROM students WHERE id=?");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc();

$stmt = $conn->prepare("
    SELECT
        ss.academic_term,
        ss.school_year,
        sub.id AS subject_id,
        sub.subject_code,
        sub.subject_name,
        sub.units,
        u.name AS teacher_name
    FROM student_subjects ss
    JOIN subjects sub ON ss.subject_id = sub.id
    LEFT JOIN users u ON sub.teacher_id = u.id
    WHERE ss.student_id = ?
    AND ss.academic_term = ?
    ORDER BY sub.subject_code
");
$stmt->bind_param("is", $student_id, $academic_term);
$stmt->execute();
$subjects = $stmt->get_result();

$rows = [];
$total_units = 0;
$total_weighted_equivalent = 0;
$graded_units = 0;
$passed_count = 0;
$failed_count = 0;
$no_data_count = 0;

while ($sub = $subjects->fetch_assoc()) {
    $grade = calculate_term_grade($conn, $student_id, intval($sub['subject_id']), $academic_term);

    $units = floatval($sub['units']);
    $total_units += $units;

    if ($grade['has_data']) {
        $equivalent = floatval($grade['equivalent']);
        $total_weighted_equivalent += $equivalent * $units;
        $graded_units += $units;

        if (($grade['remarks'] ?? '') === 'Passed') {
            $passed_count++;
        } else {
            $failed_count++;
        }
    } else {
        $no_data_count++;
    }

    $rows[] = [
        "subject" => $sub,
        "grade" => $grade
    ];
}

$gwa = $graded_units > 0 ? round($total_weighted_equivalent / $graded_units, 2) : null;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>All Grades</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php nav_bar(); ?>

<div class="container">
    <h1>All My Grades</h1>

    <form method="GET" class="card grid grid-3 no-print">
        <select name="academic_term">
            <option value="1st Term" <?= $academic_term === '1st Term' ? 'selected' : '' ?>>1st Term</option>
            <option value="2nd Term" <?= $academic_term === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
            <option value="3rd Term" <?= $academic_term === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
        </select>

        <button class="btn btn-yellow">View Term</button>
        <button type="button" onclick="window.print()" class="btn btn-blue">Print Report</button>
    </form>

    <br>

    <div class="grid grid-4">
        <div class="card-yellow">
            <h2><?= safe($academic_term) ?></h2>
            <p><?= safe($student['student_no'] ?? '') ?> - <?= safe($student['name'] ?? $_SESSION['name']) ?></p>
        </div>

        <div class="card">
            <h2 style="color:#facc15;">Total Units</h2>
            <p style="font-size:28px; font-weight:bold;"><?= safe($total_units) ?></p>
        </div>

        <div class="card">
            <h2 style="color:#facc15;">GWA</h2>
            <p style="font-size:28px; font-weight:bold;"><?= $gwa !== null ? safe($gwa) : 'No Data' ?></p>
        </div>

        <div class="card">
            <h2 style="color:#facc15;">Summary</h2>
            <p>Passed: <?= safe($passed_count) ?></p>
            <p>Failed: <?= safe($failed_count) ?></p>
            <p>No Data: <?= safe($no_data_count) ?></p>
        </div>
    </div>

    <br>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Subject Code</th>
                    <th>Subject Name</th>
                    <th>Units</th>
                    <th>Teacher</th>
                    <th>Prelim</th>
                    <th>Midterm</th>
                    <th>Finals</th>
                    <th>Term Grade</th>
                    <th>Equivalent</th>
                    <th>Remarks</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $sub = $row['subject'];
                            $grade = $row['grade'];
                        ?>
                        <tr>
                            <td data-label="Subject Code"><?= safe($sub['subject_code']) ?></td>
                            <td data-label="Subject Name"><?= safe($sub['subject_name']) ?></td>
                            <td data-label="Units"><?= safe($sub['units']) ?></td>
                            <td data-label="Teacher"><?= safe($sub['teacher_name'] ?? 'Not assigned') ?></td>
                            <td data-label="Prelim">
                                <?= $grade['periods']['Prelim']['has_data'] ? safe($grade['periods']['Prelim']['percentage']) . '%' : 'No Data' ?>
                            </td>
                            <td data-label="Midterm">
                                <?= $grade['periods']['Midterm']['has_data'] ? safe($grade['periods']['Midterm']['percentage']) . '%' : 'No Data' ?>
                            </td>
                            <td data-label="Finals">
                                <?= $grade['periods']['Finals']['has_data'] ? safe($grade['periods']['Finals']['percentage']) . '%' : 'No Data' ?>
                            </td>
                            <td data-label="Term Grade">
                                <?= $grade['has_data'] ? safe($grade['percentage']) . '%' : 'No Data' ?>
                            </td>
                            <td data-label="Equivalent">
                                <?= $grade['has_data'] ? safe($grade['equivalent']) : 'No Data' ?>
                            </td>
                            <td data-label="Remarks">
                                <?= $grade['has_data'] ? safe($grade['remarks']) : 'No Data' ?>
                            </td>
                            <td data-label="Details">
                                <a class="btn btn-yellow" href="student_grade_report.php?subject_id=<?= $sub['subject_id'] ?>&academic_term=<?= urlencode($academic_term) ?>">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" style="text-align:center;">No subjects assigned for this term.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <br>

    <a href="user.php?academic_term=<?= urlencode($academic_term) ?>" class="btn btn-gray no-print">Back to Dashboard</a>
</div>
</body>
</html>
