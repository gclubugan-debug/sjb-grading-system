<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_once 'grade_functions.php';
require_login(['student']);

$student_id = intval($_SESSION['student_id']);
$academic_term = $_GET['academic_term'] ?? '1st Term';
$year_level = $_GET['year_level'] ?? '';
$school_year = $_GET['school_year'] ?? '';

$year_levels = $conn->prepare("
    SELECT DISTINCT s.year_level
    FROM students s
    WHERE s.id = ?
    AND s.year_level IS NOT NULL
    AND s.year_level != ''
    ORDER BY s.year_level
");
$year_levels->bind_param("i", $student_id);
$year_levels->execute();
$year_level_options = $year_levels->get_result();

$school_years = $conn->prepare("
    SELECT DISTINCT school_year
    FROM student_subjects
    WHERE student_id = ?
    AND school_year IS NOT NULL
    AND school_year != ''
    ORDER BY school_year DESC
");
$school_years->bind_param("i", $student_id);
$school_years->execute();
$school_year_options = $school_years->get_result();

$query = "
    SELECT 
        ss.academic_term,
        ss.school_year,
        s.year_level,
        sub.id AS subject_id,
        sub.subject_code,
        sub.subject_name,
        sub.units,
        u.name AS teacher_name
    FROM student_subjects ss
    JOIN subjects sub ON ss.subject_id = sub.id
    JOIN students s ON ss.student_id = s.id
    LEFT JOIN users u ON sub.teacher_id = u.id
    WHERE ss.student_id = ?
    AND ss.academic_term = ?
";

$params = [$student_id, $academic_term];
$types = "is";

if ($year_level !== '') {
    $query .= " AND s.year_level = ?";
    $params[] = $year_level;
    $types .= "s";
}

if ($school_year !== '') {
    $query .= " AND ss.school_year = ?";
    $params[] = $school_year;
    $types .= "s";
}

$query .= " ORDER BY sub.subject_code";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$subjects = $stmt->get_result();

$total_units = 0;
$subject_rows = [];

while ($row = $subjects->fetch_assoc()) {
    $subject_rows[] = $row;
    $total_units += floatval($row['units']);
}

$grade_link = "student_all_grades.php?academic_term=" . urlencode($academic_term);

if ($year_level !== '') {
    $grade_link .= "&year_level=" . urlencode($year_level);
}

if ($school_year !== '') {
    $grade_link .= "&school_year=" . urlencode($school_year);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php nav_bar(); ?>

<div class="container">
    <h1>Welcome, <?= safe($_SESSION['name']) ?>!</h1>

    <form method="GET" class="card grid grid-4 no-print">
        <select name="academic_term">
            <option value="1st Term" <?= $academic_term === '1st Term' ? 'selected' : '' ?>>1st Term</option>
            <option value="2nd Term" <?= $academic_term === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
            <option value="3rd Term" <?= $academic_term === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
        </select>

        <select name="year_level">
            <option value="">All Year Levels</option>
            <?php if ($year_level_options && $year_level_options->num_rows > 0): ?>
                <?php while($yl = $year_level_options->fetch_assoc()): ?>
                    <option value="<?= safe($yl['year_level']) ?>" <?= $year_level === $yl['year_level'] ? 'selected' : '' ?>>
                        <?= safe($yl['year_level']) ?>
                    </option>
                <?php endwhile; ?>
            <?php else: ?>
                <option value="1st Year" <?= $year_level === '1st Year' ? 'selected' : '' ?>>1st Year</option>
                <option value="2nd Year" <?= $year_level === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                <option value="3rd Year" <?= $year_level === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                <option value="4th Year" <?= $year_level === '4th Year' ? 'selected' : '' ?>>4th Year</option>
            <?php endif; ?>
        </select>

        <select name="school_year">
            <option value="">All School Years</option>
            <?php if ($school_year_options && $school_year_options->num_rows > 0): ?>
                <?php while($sy = $school_year_options->fetch_assoc()): ?>
                    <option value="<?= safe($sy['school_year']) ?>" <?= $school_year === $sy['school_year'] ? 'selected' : '' ?>>
                        <?= safe($sy['school_year']) ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>

        <button class="btn btn-yellow">Apply Filter</button>
    </form>

    <br>

    <div class="card-yellow">
        <h2><?= safe($academic_term) ?> Subjects</h2>
        <p><strong>Year Level:</strong> <?= $year_level !== '' ? safe($year_level) : 'All Year Levels' ?></p>
        <p><strong>School Year:</strong> <?= $school_year !== '' ? safe($school_year) : 'All School Years' ?></p>
        <p>Total Units: <?= safe($total_units) ?></p>
        <a class="btn btn-blue" href="<?= safe($grade_link) ?>">
            View All Grades
        </a>
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
                    <th>Year Level</th>
                    <th>School Year</th>
                    <th>Current Grade</th>
                    <th>Equivalent</th>
                    <th>Remarks</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($subject_rows) > 0): ?>
                    <?php foreach($subject_rows as $sub): ?>
                        <?php 
                            $grade = calculate_term_grade($conn, $student_id, intval($sub['subject_id']), $academic_term); 
                            $details_link = "student_grade_report.php?subject_id=" . urlencode($sub['subject_id']) . "&academic_term=" . urlencode($academic_term);

                            if ($year_level !== '') {
                                $details_link .= "&year_level=" . urlencode($year_level);
                            }

                            if ($school_year !== '') {
                                $details_link .= "&school_year=" . urlencode($school_year);
                            }
                        ?>
                        <tr>
                            <td data-label="Subject Code"><?= safe($sub['subject_code']) ?></td>
                            <td data-label="Subject Name"><?= safe($sub['subject_name']) ?></td>
                            <td data-label="Units"><?= safe($sub['units']) ?></td>
                            <td data-label="Teacher"><?= safe($sub['teacher_name'] ?? 'Not assigned') ?></td>
                            <td data-label="Year Level"><?= safe($sub['year_level'] ?? 'Not set') ?></td>
                            <td data-label="School Year"><?= safe($sub['school_year'] ?? 'Not set') ?></td>
                            <td data-label="Current Grade">
                                <?= $grade['has_data'] ? safe($grade['percentage']) . '%' : 'No Data' ?>
                            </td>
                            <td data-label="Equivalent">
                                <?= $grade['has_data'] ? safe($grade['equivalent']) : 'No Data' ?>
                            </td>
                            <td data-label="Remarks">
                                <?= $grade['has_data'] ? safe($grade['remarks']) : 'No Data' ?>
                            </td>
                            <td data-label="Action">
                                <a class="btn btn-yellow" href="<?= safe($details_link) ?>">
                                    View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" style="text-align:center;">No subjects assigned for the selected filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
