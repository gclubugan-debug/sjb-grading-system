<?php
session_start();
require_once 'audit_helper.php';
require_once 'db.php';
require_once 'helpers.php';

require_login(['admin']);

$template_id = intval($_GET['template_id'] ?? ($_POST['template_id'] ?? 0));
$message = "";
$error = "";

$template_stmt = $conn->prepare("SELECT * FROM subject_templates WHERE id=?");
$template_stmt->bind_param("i", $template_id);
$template_stmt->execute();
$template = $template_stmt->get_result()->fetch_assoc();

if (!$template) {
    die("Template not found.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === "add_subjects") {
        $subject_ids = $_POST['subject_ids'] ?? [];
        $added_count = 0;

        if (empty($subject_ids)) {
            $error = "Please select at least one subject.";
        } else {
            foreach ($subject_ids as $subject_id) {
                $subject_id = intval($subject_id);

                if ($subject_id <= 0) {
                    continue;
                }

                $stmt = $conn->prepare("INSERT IGNORE INTO subject_template_items (template_id, subject_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $template_id, $subject_id);

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $added_count++;
                }
            }

            $message = $added_count > 0
                ? "$added_count subject(s) added to template."
                : "No new subjects were added. Selected subjects may already be included.";

            if (function_exists('log_audit')) {
                log_audit($conn, "Add Subjects to Template", "Added $added_count subject(s) to template ID $template_id.");
            }
        }
    }

    if ($action === "remove_subject") {
        $template_item_id = intval($_POST['template_item_id'] ?? 0);

        $stmt = $conn->prepare("DELETE FROM subject_template_items WHERE id=? AND template_id=?");
        $stmt->bind_param("ii", $template_item_id, $template_id);

        if ($stmt->execute()) {
            $message = "Subject removed from template.";

            if (function_exists('log_audit')) {
                log_audit($conn, "Remove Subject from Template", "Removed template item ID $template_item_id from template ID $template_id.");
            }
        } else {
            $error = "Error: " . $stmt->error;
        }
    }
}

$subjects = $conn->query("
    SELECT *
    FROM subjects
    WHERE id NOT IN (
        SELECT subject_id
        FROM subject_template_items
        WHERE template_id = $template_id
    )
    ORDER BY subject_code
");

$current_subjects = $conn->query("
    SELECT
        sti.id,
        sub.subject_code,
        sub.subject_name,
        sub.units
    FROM subject_template_items sti
    JOIN subjects sub ON sti.subject_id = sub.id
    WHERE sti.template_id = $template_id
    ORDER BY sub.subject_code
");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Template Subjects</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .subject-list {
            max-height: 420px;
            overflow-y: auto;
            background: #111;
            border: 1px solid #facc15;
            border-radius: 12px;
            padding: 12px;
        }

        .subject-check {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            background: #000;
            border: 1px solid #facc15;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 8px;
        }

        .subject-check input {
            width: auto;
            margin-top: 5px;
        }

        .subject-code {
            color: #facc15;
            font-weight: bold;
        }
    </style>
</head>
<body>
<?php nav_bar(); ?>

<div class="container">
    <h1>Manage Template Subjects</h1>

    <div class="card">
        <h2><?= safe($template['template_name']) ?></h2>
        <p><b>Term:</b> <?= safe($template['academic_term']) ?></p>
        <p><b>School Year:</b> <?= safe($template['school_year']) ?></p>
        <a href="enrollment.php?tab=templates" class="btn btn-gray">Back to Student Subjects</a>
    </div>

    <br>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= safe($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-2">
        <form method="POST" class="card grid">
            <h2>Add Subjects</h2>

            <input type="hidden" name="action" value="add_subjects">
            <input type="hidden" name="template_id" value="<?= $template_id ?>">

            <input type="text" id="subjectSearch" placeholder="Search subject code or name...">

            <div class="quick-buttons no-print">
                <button type="button" class="btn btn-yellow" onclick="toggleVisibleSubjects(true)">Select Visible</button>
                <button type="button" class="btn btn-gray" onclick="toggleVisibleSubjects(false)">Clear Visible</button>
            </div>

            <div class="subject-list">
                <?php if ($subjects && $subjects->num_rows > 0): ?>
                    <?php while ($s = $subjects->fetch_assoc()): ?>
                        <label class="subject-check subject-row">
                            <input type="checkbox" name="subject_ids[]" value="<?= $s['id'] ?>">
                            <span>
                                <span class="subject-code"><?= safe($s['subject_code']) ?></span><br>
                                <?= safe($s['subject_name']) ?><br>
                                <small>Units: <?= safe($s['units']) ?></small>
                            </span>
                        </label>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>No available subjects to add.</p>
                <?php endif; ?>
            </div>

            <button class="btn btn-yellow">Add Selected Subjects</button>
        </form>

        <div class="card">
            <h2>Current Template Subjects</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Subject</th>
                            <th>Units</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($current_subjects && $current_subjects->num_rows > 0): ?>
                            <?php while ($cs = $current_subjects->fetch_assoc()): ?>
                                <tr>
                                    <td><?= safe($cs['subject_code']) ?></td>
                                    <td><?= safe($cs['subject_name']) ?></td>
                                    <td><?= safe($cs['units']) ?></td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Remove this subject from template?');">
                                            <input type="hidden" name="action" value="remove_subject">
                                            <input type="hidden" name="template_id" value="<?= $template_id ?>">
                                            <input type="hidden" name="template_item_id" value="<?= $cs['id'] ?>">
                                            <button class="btn btn-red">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align:center;">No subjects in this template yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const searchBox = document.getElementById("subjectSearch");

if (searchBox) {
    searchBox.addEventListener("keyup", function() {
        const search = this.value.toLowerCase();

        document.querySelectorAll(".subject-row").forEach(function(row) {
            row.style.display = row.innerText.toLowerCase().includes(search) ? "" : "none";
        });
    });
}

function toggleVisibleSubjects(state) {
    document.querySelectorAll(".subject-row").forEach(function(row) {
        if (row.style.display !== "none") {
            const checkbox = row.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.checked = state;
            }
        }
    });
}
</script>

</body>
</html>
