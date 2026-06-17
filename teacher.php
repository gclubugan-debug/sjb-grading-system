<?php

session_start();

require_once 'db.php';
require_once 'helpers.php';

require_login(['teacher']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    
 
<?php nav_bar(); ?>

<div class="container">

    <h1>
        Welcome, <?= safe($_SESSION['name']) ?>!
    </h1>

    <div class="grid grid-3">

        <div class="card-yellow">

            <h3>Quick Action</h3>

            <a href="grade_entry.php" class="btn btn-gray">
                Input Grades
            </a>

        </div>

        <div class="card">

            <h3 style="color:#facc15;">
                AI Monitoring
            </h3>

            <a href="reports.php" class="btn btn-yellow">
                View Reports
            </a>

        </div>

        <div class="card">

            <h3 style="color:#facc15;">
                Students
            </h3>

            <a href="students.php" class="btn btn-yellow">
                View Students
            </a>

        </div>

    </div>

</div>

</body>
</html>