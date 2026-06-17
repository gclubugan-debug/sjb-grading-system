<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_once 'grade_functions.php';
require_login(['admin','teacher','student']);

function table_exists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

function column_exists($conn, $table, $column) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

$student_id = $_GET['student_id'] ?? ($_SESSION['student_id'] ?? null);
$subject_id = $_GET['subject_id'] ?? '';
$year_level = '';
$school_year = $_GET['school_year'] ?? '';
$term = $_GET['academic_term'] ?? '1st Term';

if ($_SESSION['role'] === 'student') {
    $student_id = intval($_SESSION['student_id']);
}

$student_id_int = intval($student_id);
$subject_id_int = intval($subject_id);

$has_school_year = column_exists($conn, 'student_subjects', 'school_year');
$has_academic_year = column_exists($conn, 'student_subjects', 'academic_year');
$school_year_column = $has_school_year ? 'school_year' : ($has_academic_year ? 'academic_year' : '');

$has_student_year_level = column_exists($conn, 'students', 'year_level');

$students = $conn->query("SELECT * FROM students ORDER BY name");

$selected_student = null;
if ($student_id_int > 0) {
    $student_stmt = $conn->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
    $student_stmt->bind_param("i", $student_id_int);
    $student_stmt->execute();
    $selected_student = $student_stmt->get_result()->fetch_assoc();

    if ($year_level === '' && $selected_student && $has_student_year_level) {
        $year_level = $selected_student['year_level'] ?? '';
    }
}

$year_levels = false;

if ($school_year_column !== '') {
    $school_years = $conn->query("
        SELECT DISTINCT `$school_year_column` AS school_year
        FROM student_subjects
        WHERE `$school_year_column` IS NOT NULL
        AND `$school_year_column` != ''
        ORDER BY `$school_year_column` DESC
    ");
} else {
    $school_years = false;
}

if ($_SESSION['role'] === 'student') {
    if ($school_year_column !== '' && $school_year !== '') {
        $subject_stmt = $conn->prepare("
            SELECT sub.*
            FROM student_subjects ss
            JOIN subjects sub ON ss.subject_id = sub.id
            WHERE ss.student_id = ?
            AND ss.academic_term = ?
            AND ss.`$school_year_column` = ?
            ORDER BY sub.subject_code
        ");
        $subject_stmt->bind_param("iss", $student_id_int, $term, $school_year);
    } else {
        $subject_stmt = $conn->prepare("
            SELECT sub.*
            FROM student_subjects ss
            JOIN subjects sub ON ss.subject_id = sub.id
            WHERE ss.student_id = ?
            AND ss.academic_term = ?
            ORDER BY sub.subject_code
        ");
        $subject_stmt->bind_param("is", $student_id_int, $term);
    }

    $subject_stmt->execute();
    $subjects = $subject_stmt->get_result();
} else {
    $subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_code");
}

$allowed_subject = true;

if ($_SESSION['role'] === 'student' && $subject_id !== '') {
    if ($school_year_column !== '' && $school_year !== '') {
        $check = $conn->prepare("
            SELECT id
            FROM student_subjects
            WHERE student_id = ?
            AND subject_id = ?
            AND academic_term = ?
            AND `$school_year_column` = ?
            LIMIT 1
        ");
        $check->bind_param("iiss", $student_id_int, $subject_id_int, $term, $school_year);
    } else {
        $check = $conn->prepare("
            SELECT id
            FROM student_subjects
            WHERE student_id = ?
            AND subject_id = ?
            AND academic_term = ?
            LIMIT 1
        ");
        $check->bind_param("iis", $student_id_int, $subject_id_int, $term);
    }

    $check->execute();
    $allowed_subject = $check->get_result()->num_rows > 0;
}

$has_subject_teacher_id = column_exists($conn, 'subjects', 'teacher_id');
$has_student_subject_teacher_id = column_exists($conn, 'student_subjects', 'teacher_id');
$has_teachers_table = table_exists($conn, 'teachers');

$grade_subject_rows = [];

if ($student_id_int > 0) {
    $teacher_select = "'Not assigned' AS teacher_name";
    $teacher_join = "";

    if ($has_teachers_table && $has_student_subject_teacher_id) {
        $teacher_select = "COALESCE(t.name, 'Not assigned') AS teacher_name";
        $teacher_join = "LEFT JOIN teachers t ON ss.teacher_id = t.id";
    } elseif ($has_teachers_table && $has_subject_teacher_id) {
        $teacher_select = "COALESCE(t.name, 'Not assigned') AS teacher_name";
        $teacher_join = "LEFT JOIN teachers t ON sub.teacher_id = t.id";
    }

    $grade_query = "
        SELECT
            sub.id,
            sub.subject_code,
            sub.subject_name,
            $teacher_select
        FROM student_subjects ss
        JOIN subjects sub ON ss.subject_id = sub.id
        $teacher_join
        WHERE ss.student_id = ?
        AND ss.academic_term = ?
    ";

    $grade_params = [$student_id_int, $term];
    $grade_types = "is";

    if ($school_year_column !== '' && $school_year !== '') {
        $grade_query .= " AND ss.`$school_year_column` = ?";
        $grade_params[] = $school_year;
        $grade_types .= "s";
    }

    $grade_query .= " ORDER BY sub.subject_code";

    $grade_stmt = $conn->prepare($grade_query);
    $grade_stmt->bind_param($grade_types, ...$grade_params);
    $grade_stmt->execute();
    $grade_subjects_result = $grade_stmt->get_result();

    while ($row = $grade_subjects_result->fetch_assoc()) {
        $grade_subject_rows[] = $row;
    }
}

$selected_subject = null;
if ($subject_id_int > 0) {
    $subject_info_stmt = $conn->prepare("SELECT * FROM subjects WHERE id = ? LIMIT 1");
    $subject_info_stmt->bind_param("i", $subject_id_int);
    $subject_info_stmt->execute();
    $selected_subject = $subject_info_stmt->get_result()->fetch_assoc();
}


$grade_report_rows = [];
$overall_percent_total = 0;
$overall_equivalent_total = 0;
$overall_count = 0;
$overall_equivalent_count = 0;

if (!empty($grade_subject_rows)) {
    foreach ($grade_subject_rows as $gs) {
        $subject_grade = calculate_term_grade($conn, $student_id_int, intval($gs['id']), $term);
        $subject_ai = ai_term_feedback($subject_grade);

        $gs['grade_data'] = $subject_grade;
        $gs['ai_data'] = $subject_ai;
        $grade_report_rows[] = $gs;

        if (!empty($subject_grade['has_data'])) {
            $overall_percent_total += floatval($subject_grade['percentage']);
            $overall_count++;

            if (isset($subject_grade['equivalent']) && is_numeric($subject_grade['equivalent'])) {
                $overall_equivalent_total += floatval($subject_grade['equivalent']);
                $overall_equivalent_count++;
            }
        }
    }
}

$overall_grade_report = [
    'has_data' => $overall_count > 0,
    'percentage' => $overall_count > 0 ? round($overall_percent_total / $overall_count, 2) : 0,
    'equivalent' => $overall_equivalent_count > 0 ? number_format($overall_equivalent_total / $overall_equivalent_count, 2) : 'No Data',
    'remarks' => $overall_count > 0 ? (($overall_percent_total / $overall_count) >= 75 ? 'Passed' : 'Failed') : 'No Data',
    'periods' => []
];

$overall_ai_feedback = ai_term_feedback($overall_grade_report);

function display_year_level($selected_student, $year_level, $has_student_year_level) {
    if ($has_student_year_level && $selected_student && !empty($selected_student['year_level'])) {
        return $selected_student['year_level'];
    }

    return $year_level !== '' ? $year_level : 'All Year Levels';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Grade Report</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .report-card {
            background: var(--panel);
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 8px 20px rgba(0,0,0,.25);
        }

        .report-card-header {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin-bottom: 18px;
        }

        .report-card-header p {
            margin: 4px 0;
        }

        .report-card-table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-card-table th,
        .report-card-table td {
            padding: 10px;
            border: 1px solid rgba(255,255,255,.18);
            text-align: left;
        }

        .report-card-table th {
            color: #facc15;
            background: rgba(0,0,0,.25);
        }

        .print-header {
            display: none;
        }

        .print-all-only {
            display: none;
        }


        .print-clean-box {
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 14px;
        }

        .print-clean-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
        }

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

            body.print-all-grades .single-subject-report {
                display: none !important;
            }

            body.print-all-grades .print-all-only {
                display: block !important;
            }

            .card,
            .card-yellow,
            .report-card {
                background: white !important;
                color: black !important;
                box-shadow: none !important;
                border: none !important;
            }

            h1,
            h2,
            h3,
            p,
            b {
                color: black !important;
            }

            .report-card-header {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr);
                gap: 4px;
                margin-bottom: 16px;
            }

            table,
            .report-card-table {
                width: 100%;
                border-collapse: collapse;
                color: black !important;
            }

            th,
            td,
            .report-card-table th,
            .report-card-table td {
                border: 1px solid black !important;
                padding: 8px;
                font-size: 12px;
                color: black !important;
            }

            th,
            .report-card-table th {
                background: #ddd !important;
            }

            .print-clean-box,
            .single-subject-report .card,
            .single-subject-report .card-yellow {
                border: 1px solid black !important;
                border-radius: 0 !important;
                padding: 12px !important;
                margin-bottom: 12px !important;
                page-break-inside: avoid;
            }

            .print-clean-grid {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }
        }
    </style>

    <script>
        function printReport() {
            document.body.classList.remove('print-all-grades');
            window.print();
        }

        function printAllGrades() {
            document.body.classList.add('print-all-grades');
            window.print();

            setTimeout(function() {
                document.body.classList.remove('print-all-grades');
            }, 500);
        }
    </script>
</head>
<body>
 
    
<?php nav_bar(); ?>

<div class="container">
    <h1>Student Grade Report</h1>

    <form method="GET" class="card grid grid-4 no-print">
        <?php if ($_SESSION['role'] !== 'student'): ?>
            <select name="student_id" required>
                <option value="">Select Student</option>
                <?php while($s = $students->fetch_assoc()): ?>
                    <option value="<?= safe($s['id']) ?>" <?= $student_id == $s['id'] ? 'selected' : '' ?>>
                        <?= safe($s['student_no']) ?> - <?= safe($s['name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        <?php endif; ?>

        <select name="subject_id">
            <option value="">All Subjects</option>
            <?php while($sub = $subjects->fetch_assoc()): ?>
                <option value="<?= safe($sub['id']) ?>" <?= $subject_id == $sub['id'] ? 'selected' : '' ?>>
                    <?= safe($sub['subject_code']) ?> - <?= safe($sub['subject_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <?php if ($school_years): ?>
            <select name="school_year">
                <option value="">All School Years</option>
                <?php while($sy = $school_years->fetch_assoc()): ?>
                    <option value="<?= safe($sy['school_year']) ?>" <?= $school_year === $sy['school_year'] ? 'selected' : '' ?>>
                        <?= safe($sy['school_year']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        <?php else: ?>
            <input name="school_year" value="<?= safe($school_year) ?>" placeholder="School Year">
        <?php endif; ?>

        <select name="academic_term">
            <option value="1st Term" <?= $term === '1st Term' ? 'selected' : '' ?>>1st Term</option>
            <option value="2nd Term" <?= $term === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
            <option value="3rd Term" <?= $term === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
        </select>

        <button class="btn btn-yellow">View</button>

        <?php if ($student_id_int > 0): ?>
            <button type="button" onclick="printReport()" class="btn btn-blue">Print Report</button>
            <button type="button" onclick="printAllGrades()" class="btn btn-yellow">Print All Grades</button>
        <?php endif; ?>
    </form>

    <br>

    <?php if ($_SESSION['role'] === 'student' && !$allowed_subject): ?>
        <div class="alert alert-danger">
            This subject is not assigned to your account for the selected term.
        </div>
    <?php elseif ($student_id_int > 0): ?>

        <div class="<?= $subject_id === '' ? 'normal-report-only' : 'print-all-only' ?>">
            <div class="print-header">
                <h2>Student Grade Report Card</h2>
                <p><strong>Name:</strong> <?= $selected_student ? safe($selected_student['name']) : 'Selected Student' ?></p>
                <p><strong>Section:</strong> <?= $selected_student ? safe($selected_student['section'] ?? '') : '' ?></p>
                <p><strong>Year Level:</strong> <?= safe(display_year_level($selected_student, $year_level, $has_student_year_level)) ?></p>
                <p><strong>School Year:</strong> <?= $school_year !== '' ? safe($school_year) : 'All School Years' ?></p>
                <p><strong>Term:</strong> <?= safe($term) ?></p>
                <p><strong>Date Printed:</strong> <?= date('F d, Y') ?></p>
            </div>

            <div class="report-card">
                <h2>Report Card</h2>

                <div class="report-card-header">
                    <p><b>Name:</b> <?= $selected_student ? safe($selected_student['name']) : 'Selected Student' ?></p>
                    <p><b>Section:</b> <?= $selected_student ? safe($selected_student['section'] ?? '') : '' ?></p>
                    <p><b>Year Level:</b> <?= safe(display_year_level($selected_student, $year_level, $has_student_year_level)) ?></p>
                    <p><b>School Year:</b> <?= $school_year !== '' ? safe($school_year) : 'All School Years' ?></p>
                    <p><b>Term:</b> <?= safe($term) ?></p>
                </div>

                <?php if ($overall_grade_report['has_data']): ?>
                    <div class="<?= $overall_ai_feedback['risk'] ? 'card' : 'card-yellow' ?> overall-grade-feedback print-clean-box" style="<?= $overall_ai_feedback['risk'] ? 'background:#7f1d1d;' : '' ?>">
                        <h2><?= safe($term) ?> Overall Grade Report Feedback</h2>
                        <p><b>Overall Grade:</b> <?= safe($overall_grade_report['percentage'].'% / '.$overall_grade_report['equivalent']) ?></p>
                        <p><b>Remarks:</b> <?= safe($overall_grade_report['remarks']) ?></p>
                        <p><b>AI Status:</b> <?= status_badge($overall_ai_feedback['status'], $overall_ai_feedback['risk']) ?></p>
                        <p><b>Feedback:</b> <?= safe($overall_ai_feedback['feedback']) ?></p>
                        <p><b>Suggestion:</b> <?= safe($overall_ai_feedback['suggestion']) ?></p>
                    </div>
                <?php endif; ?>

                <div class="table-wrap">
                    <table class="report-card-table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject</th>
                                <th>Teacher</th>
                                <th>Prelim</th>
                                <th>Midterm</th>
                                <th>Finals</th>
                                <th>Term Grade</th>
                                <th>Equivalent</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($grade_report_rows)): ?>
                                <?php foreach($grade_report_rows as $gs): ?>
                                    <?php $subject_grade = $gs['grade_data']; ?>
                                    <tr>
                                        <td><?= safe($gs['subject_code']) ?></td>
                                        <td><?= safe($gs['subject_name']) ?></td>
                                        <td><?= safe($gs['teacher_name']) ?></td>
                                        <td>
                                            <?= $subject_grade['periods']['Prelim']['has_data'] ? safe($subject_grade['periods']['Prelim']['percentage'].'%') : 'No Data' ?>
                                        </td>
                                        <td>
                                            <?= $subject_grade['periods']['Midterm']['has_data'] ? safe($subject_grade['periods']['Midterm']['percentage'].'%') : 'No Data' ?>
                                        </td>
                                        <td>
                                            <?= $subject_grade['periods']['Finals']['has_data'] ? safe($subject_grade['periods']['Finals']['percentage'].'%') : 'No Data' ?>
                                        </td>
                                        <td>
                                            <?= $subject_grade['has_data'] ? safe($subject_grade['percentage'].'%') : 'No Data' ?>
                                        </td>
                                        <td>
                                            <?= $subject_grade['has_data'] ? safe($subject_grade['equivalent']) : 'No Data' ?>
                                        </td>
                                        <td><?= safe($subject_grade['remarks'] ?? 'No Data') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align:center;">
                                        No assigned subjects found for this student, school year, and term.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($subject_id !== ''): ?>
            <?php
                $tr = calculate_term_grade($conn, $student_id_int, $subject_id_int, $term);
                $ai = ai_term_feedback($tr);
            ?>

            <div class="single-subject-report">
                <?php if ($selected_subject): ?>
                    <div class="card print-clean-box">
                        <h2><?= safe($selected_subject['subject_code']) ?> - <?= safe($selected_subject['subject_name']) ?></h2>
                        <p><b>Student:</b> <?= $selected_student ? safe($selected_student['name']) : 'Selected Student' ?></p>
                        <p><b>Section:</b> <?= $selected_student ? safe($selected_student['section'] ?? '') : '' ?></p>
                        <p><b>Year Level:</b> <?= safe(display_year_level($selected_student, $year_level, $has_student_year_level)) ?></p>
                        <p><b>School Year:</b> <?= $school_year !== '' ? safe($school_year) : 'All School Years' ?></p>
                        <p><b>Term:</b> <?= safe($term) ?></p>
                    </div>

                    <br>
                <?php endif; ?>

                <div class="<?= $ai['risk'] ? 'card' : 'card-yellow' ?> ai-feedback print-clean-box" style="<?= $ai['risk'] ? 'background:#7f1d1d;' : '' ?>">
                    <h2><?= safe($term) ?> Overall AI Feedback</h2>
                    <p><b>Term Grade:</b> <?= $tr['has_data'] ? safe($tr['percentage'].'% / '.$tr['equivalent']) : 'No Data' ?></p>
                    <p><b>Remarks:</b> <?= safe($tr['remarks']) ?></p>
                    <p><b>AI Status:</b> <?= status_badge($ai['status'], $ai['risk']) ?></p>
                    <p><b>Feedback:</b> <?= safe($ai['feedback']) ?></p>
                    <p><b>Suggestion:</b> <?= safe($ai['suggestion']) ?></p>
                </div>

                <br>

                <div class="grid grid-3 print-clean-grid">
                    <?php foreach(['Prelim','Midterm','Finals'] as $p): ?>
                        <?php $pf = ai_period_feedback($p, $tr['periods'][$p]); ?>
                        <div class="card print-clean-box">
                            <h2 style="color:#facc15;"><?= safe($p) ?></h2>
                            <p><b>Grade:</b> <?= $tr['periods'][$p]['has_data'] ? safe($tr['periods'][$p]['percentage'].'% / '.$tr['periods'][$p]['equivalent']) : 'No Data' ?></p>
                            <p class="ai-feedback"><b>Status:</b> <?= status_badge($pf['status'], $pf['risk']) ?></p>
                            <p class="ai-feedback"><b>AI Feedback:</b> <?= safe($pf['feedback']) ?></p>
                            <p class="ai-feedback"><b>Suggestion:</b> <?= safe($pf['suggestion']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <p>Select a student to view report.</p>
    <?php endif; ?>
</div>
</body>
</html>
