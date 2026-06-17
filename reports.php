<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_once 'grade_functions.php';
require_once 'ai_api.php';
require_login(['admin','teacher']);

$term = $_GET['academic_term'] ?? '1st Term';
$subject_id = $_GET['subject_id'] ?? '';
$course = $_GET['course'] ?? '';
$section = $_GET['section'] ?? '';
$school_year = $_GET['school_year'] ?? '';

$subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_code");
$courses = $conn->query("SELECT DISTINCT course FROM students ORDER BY course");
$sections = $conn->query("SELECT DISTINCT section FROM students ORDER BY section");
$school_years = $conn->query("SELECT DISTINCT school_year FROM student_subjects WHERE school_year IS NOT NULL AND school_year <> '' ORDER BY school_year DESC");

$subject_name = "Selected Subject";

if ($subject_id !== '') {
    $subject_stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE id=?");
    $subject_id_int = intval($subject_id);
    $subject_stmt->bind_param("i", $subject_id_int);
    $subject_stmt->execute();
    $subject_result = $subject_stmt->get_result();

    if ($subject_result && $subject_result->num_rows > 0) {
        $subject_row = $subject_result->fetch_assoc();
        $subject_name = $subject_row['subject_name'];
    }
}

$query = "SELECT DISTINCT s.* FROM students s";
$params = [];
$types = "";

if ($subject_id !== '') {
    $query .= " INNER JOIN student_subjects ss ON ss.student_id = s.id";
}

$query .= " WHERE 1";

if ($subject_id !== '') {
    $query .= " AND ss.subject_id=? AND ss.academic_term=?";
    $params[] = intval($subject_id);
    $params[] = $term;
    $types .= "is";

    if ($school_year !== '') {
        $query .= " AND ss.school_year=?";
        $params[] = $school_year;
        $types .= "s";
    }
}

if ($course !== '') {
    $query .= " AND s.course=?";
    $params[] = $course;
    $types .= "s";
}

if ($section !== '') {
    $query .= " AND s.section=?";
    $params[] = $section;
    $types .= "s";
}

$query .= " ORDER BY s.course, s.section, s.name";

$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$students = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>AI Reports</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .ai-modal-overlay {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,.75);
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .ai-modal-box {
            background: #1f1f1f;
            color: #fff;
            width: 95%;
            max-width: 850px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 14px;
            padding: 20px;
            border: 2px solid #facc15;
            position: relative;
        }

        .ai-modal-close {
            position: sticky;
            top: 0;
            float: right;
            background: #dc2626;
            color: #fff;
            border: 0;
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
            font-weight: bold;
        }

        .ai-loading {
            text-align: center;
            padding: 35px;
            font-size: 18px;
        }
    </style>
</head>
<body>
 
<?php nav_bar(); ?>

<div class="container">
    <h1>AI At-Risk Report</h1>

    <form method="GET" class="card grid grid-4 no-print">
        <select name="academic_term">
            <option value="1st Term" <?= $term === '1st Term' ? 'selected' : '' ?>>1st Term</option>
            <option value="2nd Term" <?= $term === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
            <option value="3rd Term" <?= $term === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
        </select>

        <select name="school_year">
            <option value="">All School Years</option>
            <?php while($sy = $school_years->fetch_assoc()): ?>
                <option value="<?= safe($sy['school_year']) ?>" <?= $school_year === $sy['school_year'] ? 'selected' : '' ?>>
                    <?= safe($sy['school_year']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <select name="subject_id" required>
            <option value="">Select Subject</option>
            <?php while($sub = $subjects->fetch_assoc()): ?>
                <option value="<?= $sub['id'] ?>" <?= $subject_id == $sub['id'] ? 'selected' : '' ?>>
                    <?= safe($sub['subject_code']) ?> - <?= safe($sub['subject_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <select name="course">
            <option value="">All Courses</option>
            <?php while($c = $courses->fetch_assoc()): ?>
                <option value="<?= safe($c['course']) ?>" <?= $course === $c['course'] ? 'selected' : '' ?>>
                    <?= safe($c['course']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <select name="section">
            <option value="">All Sections</option>
            <?php while($s = $sections->fetch_assoc()): ?>
                <option value="<?= safe($s['section']) ?>" <?= $section === $s['section'] ? 'selected' : '' ?>>
                    <?= safe($s['section']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <button class="btn btn-yellow">Generate</button>
        <button type="button" onclick="window.print()" class="btn btn-blue">Print Report</button>
    </form>

    <br>

    <?php if ($subject_id): ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Course/Section</th>
                        <th>Prelim</th>
                        <th>Midterm</th>
                        <th>Finals</th>
                        <th>Term Grade</th>
                        <th>AI Status</th>
                        <th>Target Grade</th>
                        <th style="width:220px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($students && $students->num_rows > 0): ?>
                        <?php while($st = $students->fetch_assoc()): ?>
                            <?php
                                $tr = calculate_term_grade($conn, $st['id'], intval($subject_id), $term);
                                $target_to_pass = calculate_target_to_pass($tr['periods']);

                                $grade_data = [
                                    "prelim" => $tr['periods']['Prelim']['percentage'] ?? 0,
                                    "midterm" => $tr['periods']['Midterm']['percentage'] ?? 0,
                                    "finals" => $tr['periods']['Finals']['percentage'] ?? 0,
                                    "term_grade" => $tr['percentage'] ?? 0,
                                    "equivalent" => $tr['equivalent'] ?? "No Grade",
                                    "remarks" => $tr['remarks'] ?? "No Data",
                                    "target_to_pass" => $target_to_pass
                                ];

                                $ai = generate_ai_feedback_api($st['name'], $subject_name, $term, $grade_data, "short");
                                $risk = in_array($ai['status'], ['At Risk', 'Failed', 'High Risk']);
                            ?>

                            <tr style="<?= $risk ? 'background:#7f1d1d;' : '' ?>">
                                <td><?= safe($st['student_no']) ?> - <?= safe($st['name']) ?></td>
                                <td><?= safe($st['course']) ?> <?= safe($st['section']) ?></td>
                                <td><?= $tr['periods']['Prelim']['has_data'] ? safe($tr['periods']['Prelim']['percentage']) . '%' : 'No Data' ?></td>
                                <td><?= $tr['periods']['Midterm']['has_data'] ? safe($tr['periods']['Midterm']['percentage']) . '%' : 'No Data' ?></td>
                                <td><?= $tr['periods']['Finals']['has_data'] ? safe($tr['periods']['Finals']['percentage']) . '%' : 'No Data' ?></td>
                                <td><?= $tr['has_data'] ? safe($tr['percentage']) . '% / ' . safe($tr['equivalent']) : 'No Data' ?></td>
                                <td><?= status_badge($ai['status'], $risk) ?></td>
                                <td>
                                    <?php if (($target_to_pass['target_grade'] ?? 75) > 75 && !empty($target_to_pass['remaining_periods'])): ?>
                                        Aim for <?= safe($target_to_pass['target_grade']) ?>%
                                    <?php else: ?>
                                        <?= safe($target_to_pass['message']) ?>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width:220px;">
                                    <button
                                        type="button"
                                        class="btn btn-yellow ai-open-btn"
                                        data-url="ai_recommendation_popup.php?student_id=<?= $st['id'] ?>&subject_id=<?= intval($subject_id) ?>&academic_term=<?= urlencode($term) ?>&school_year=<?= urlencode($school_year) ?>">
                                        AI Report
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center;">No students found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>Select a subject to generate report.</p>
    <?php endif; ?>
</div>

<div id="aiModal" class="ai-modal-overlay">
    <div class="ai-modal-box">
        <button type="button" class="ai-modal-close" onclick="closeAiModal()">Close</button>
        <div id="aiModalContent" class="ai-loading">Generating AI recommendation...</div>
    </div>
</div>

<script>
function openAiModal(url) {
    const modal = document.getElementById("aiModal");
    const content = document.getElementById("aiModalContent");

    modal.style.display = "flex";
    content.innerHTML = '<div class="ai-loading">Generating AI recommendation...</div>';

    fetch(url)
        .then(response => response.json())
        .then(data => {
            content.innerHTML = data.html || "<p>No AI response received.</p>";
        })
        .catch(() => {
            content.innerHTML = "<p>Unable to generate AI recommendation. Please try again.</p>";
        });
}

function closeAiModal() {
    document.getElementById("aiModal").style.display = "none";
}

document.addEventListener("click", function(e) {
    if (e.target.classList.contains("ai-open-btn")) {
        openAiModal(e.target.getAttribute("data-url"));
    }

    if (e.target.id === "aiModal") {
        closeAiModal();
    }
});
</script>

</body>
</html>