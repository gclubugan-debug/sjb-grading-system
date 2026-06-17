<?php
session_start();

require_once 'db.php';
require_once 'helpers.php';
require_once 'grade_functions.php';

require_login(['admin']);

$students_count = $conn->query(
    "SELECT COUNT(*) AS total FROM students"
)->fetch_assoc()['total'];

$teachers_count = $conn->query(
    "SELECT COUNT(*) AS total FROM users WHERE role='teacher'"
)->fetch_assoc()['total'];

$pending_count = $conn->query(
    "SELECT COUNT(*) AS total FROM pending_requests WHERE status='pending'"
)->fetch_assoc()['total'];

$subjects_count = $conn->query(
    "SELECT COUNT(*) AS total FROM subjects"
)->fetch_assoc()['total'];

$sections_count = $conn->query(
    "SELECT COUNT(*) AS total FROM sections"
)->fetch_assoc()['total'];

$requests = $conn->query(
    "SELECT * FROM pending_requests 
     WHERE status='pending' 
     ORDER BY registered_at DESC"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>


<?php nav_bar(); ?>

<div class="container">

    <h1>Welcome, Admin!</h1>

    <div class="grid grid-4">

        <div class="card-yellow">
            <h3>Total Students</h3>
            <h1><?= $students_count ?></h1>
        </div>

        <div class="card-yellow">
            <h3>Total Teachers</h3>
            <h1><?= $teachers_count ?></h1>
        </div>

        <div class="card-yellow">
            <h3>Total Subjects</h3>
            <h1><?= $subjects_count ?></h1>
        </div>

        <div class="card-yellow">
            <h3>Total Sections</h3>
            <h1><?= $sections_count ?></h1>
        </div>

        <div class="card-yellow">
            <h3>Pending Requests</h3>
            <h1><?= $pending_count ?></h1>
        </div>

    </div>

    <br>

    <div class="card">

        <h2 style="color:#facc15;">Student Requests</h2>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Student No.</th>
                        <th>Name</th>
                        <th>Mobile</th>
                        <th>Emergency Contact</th>
                        <th>Course</th>
                        <th>Section</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($requests && $requests->num_rows > 0): ?>

                    <?php while ($row = $requests->fetch_assoc()): ?>

                        <tr>
                            <td><?= safe($row['student_no']) ?></td>

                            <td><?= safe($row['name']) ?></td>

                            <td><?= safe($row['mobile_number']) ?></td>

                            <td>
                                <?= safe($row['emergency_contact_person']) ?>
                                /
                                <?= safe($row['emergency_contact_number']) ?>
                            </td>

                            <td><?= safe($row['course']) ?></td>

                            <td><?= safe($row['section']) ?></td>

                            <td>

                                <form method="POST" action="requesthandle.php" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-green">
                                        Approve
                                    </button>
                                </form>

                                <form method="POST" action="requesthandle.php" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="btn btn-red">
                                        Delete
                                    </button>
                                </form>

                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" style="text-align:center;">
                            No pending requests
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>
</html>