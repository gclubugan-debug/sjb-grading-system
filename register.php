<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';
require_once 'helpers.php';

$message = "";

$sections_result = $conn->query("SELECT section_name FROM sections ORDER BY section_name");
$courses_result = $conn->query("
    SELECT DISTINCT course FROM students 
    WHERE course IS NOT NULL AND course != ''
    UNION
    SELECT DISTINCT course FROM pending_requests 
    WHERE course IS NOT NULL AND course != ''
    ORDER BY course
");

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $student_no = trim($_POST['student_no']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $mobile = trim($_POST['mobile_number']);
    $ep = trim($_POST['emergency_contact_person']);
    $en = trim($_POST['emergency_contact_number']);
    $course = trim($_POST['course']);
    $section = trim($_POST['section']);
    $year = trim($_POST['year']);

    $p = $_POST['password'];
    $cp = $_POST['confirm_password'];

    if ($p !== $cp) {

        $message = "Passwords do not match. Please try again.";

    } else {

        $hash = password_hash($p, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "INSERT INTO pending_requests (
                student_no,
                name,
                email,
                mobile_number,
                emergency_contact_person,
                emergency_contact_number,
                course,
                section,
                year_level,
                password
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "ssssssssss",
            $student_no,
            $name,
            $email,
            $mobile,
            $ep,
            $en,
            $course,
            $section,
            $year,
            $hash
        );

        $message = $stmt->execute()
            ? "Registration submitted. Wait for admin approval."
            : "Error: " . $stmt->error;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register</title>
    <link rel="stylesheet" href="style.css">
</head>

<body style="min-height:100vh; display:flex; align-items:center; justify-content:center;">
    

    <div class="card" style="max-width:520px; width:96%;">
        
        <img src="sjb_logo.jpg"
             alt="SJB Logo"
             style="width:140px;height:140px;object-fit:cover;border-radius:50%;border:4px solid #facc15;display:block;margin:0 auto 20px auto;box-shadow:0 6px 16px rgba(0,0,0,.35);">


        <h2 style="text-align:center; color:#facc15;">
            Student Registration
        </h2>

        <?php if ($message): ?>

            <div class="alert <?= (strpos($message, 'Error') !== false || strpos($message, 'match') !== false) ? 'alert-danger' : 'alert-success' ?>">
                <?= safe($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST" class="grid grid-2">

            <input
                name="student_no"
                placeholder="Student Number"
                required
            >

            <input
                name="name"
                placeholder="Full Name"
                required
            >

            <input
                type="email"
                name="email"
                placeholder="Email"
                required
            >

            <input
                name="mobile_number"
                placeholder="Mobile Number"
                required
            >

            <input
                name="emergency_contact_person"
                placeholder="Emergency Contact Person"
                required
            >

            <input
                name="emergency_contact_number"
                placeholder="Emergency Contact Mobile Number"
                required
            >

            <select name="course" required>
                <option value="">Select Course</option>
                <?php if ($courses_result && $courses_result->num_rows > 0): ?>
                    <?php while($crs = $courses_result->fetch_assoc()): ?>
                        <option value="<?= safe($crs['course']) ?>">
                            <?= safe($crs['course']) ?>
                        </option>
                    <?php endwhile; ?>
                <?php else: ?>
                    <option value="BSIT">BSIT</option>
                <?php endif; ?>
            </select>

            <select name="section" required>
                <option value="">Select Section</option>
                <?php if ($sections_result && $sections_result->num_rows > 0): ?>
                    <?php while($sec = $sections_result->fetch_assoc()): ?>
                        <option value="<?= safe($sec['section_name']) ?>">
                            <?= safe($sec['section_name']) ?>
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>

            <select name="year" required>
                <option value="">Select Year Level</option>
                <option value="1st Year">1st Year</option>
                <option value="2nd Year">2nd Year</option>
                <option value="3rd Year">3rd Year</option>
                <option value="4th Year">4th Year</option>
            </select>

            <input
                type="password"
                name="password"
                placeholder="Password"
                required
            >

            <input
                type="password"
                name="confirm_password"
                placeholder="Re-enter Password"
                required
            >

            <button class="btn btn-yellow" style="grid-column:1/-1;">
                Register
            </button>

        </form>

        <p style="text-align:center;">
            <a href="index.php" style="color:#facc15;">
                Back to Login
            </a>
        </p>

    </div>

</body>
</html>