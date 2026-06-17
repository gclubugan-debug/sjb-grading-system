<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'audit_helper.php';
require_once 'db.php';
require_once 'helpers.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST['email']);
    $pass = $_POST['password'];

    // Demo Admin Account
    if ($email === "admin" && $pass === "admin123") {
        $_SESSION['role'] = 'admin';
        $_SESSION['user_id'] = 1;
        $_SESSION['name'] = 'Administrator';

        log_audit($conn, "Login", "Demo admin logged in successfully.");

        header("Location: admin.php");
        exit();
    }

    // Demo Teacher Account
    if ($email === "teacher@sjb.edu" && $pass === "teacher123") {
        $demo_teacher_status = 'active';

        $demo_stmt = $conn->prepare("SELECT status FROM users WHERE id=? AND role='teacher' LIMIT 1");
        $demo_teacher_id = 2;
        $demo_stmt->bind_param("i", $demo_teacher_id);
        $demo_stmt->execute();
        $demo_result = $demo_stmt->get_result();

        if ($demo_result && $demo_result->num_rows > 0) {
            $demo_row = $demo_result->fetch_assoc();
            $demo_teacher_status = $demo_row['status'] ?? 'active';
        }

        if ($demo_teacher_status === 'suspended') {
            log_audit($conn, "Blocked Login", "Suspended demo teacher attempted login. Teacher ID: 2.");
            $error = "Your account has been suspended. Please contact the administrator.";
        } else {
            $_SESSION['role'] = 'teacher';
            $_SESSION['user_id'] = 2;
            $_SESSION['name'] = 'Sample Teacher';

            log_audit($conn, "Login", "Demo teacher logged in successfully.");

            header("Location: teacher.php");
            exit();
        }
    }

    // Check Admin / Teacher Accounts
    $stmt = $conn->prepare(
        "SELECT *
        FROM users
        WHERE email = ?
        AND role IN ('admin', 'teacher')"
    );

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $ur = $stmt->get_result();

    if ($ur && $ur->num_rows > 0) {

        $u = $ur->fetch_assoc();

        if (password_verify($pass, $u['password'])) {

            if (($u['status'] ?? 'active') === 'suspended') {

                log_audit(
                    $conn,
                    "Blocked Login",
                    "Suspended " . $u['role'] . " attempted login. User ID: " . $u['id']
                );

                $error = "Your account has been suspended. Please contact the administrator.";

            } else {

                $_SESSION['role'] = $u['role'];
                $_SESSION['user_id'] = $u['id'];
                $_SESSION['name'] = $u['name'];

                log_audit($conn, "Login", ucfirst($u['role']) . " logged in successfully.");

                header(
                    $u['role'] === 'admin'
                        ? "Location: admin.php"
                        : "Location: teacher.php"
                );

                exit();
            }
        }
    }

    // Check Student Accounts
    $stmt = $conn->prepare(
        "SELECT *
        FROM students
        WHERE email = ?
        LIMIT 1"
    );

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $sr = $stmt->get_result();

    if ($sr && $sr->num_rows > 0) {

        $s = $sr->fetch_assoc();

        if (password_verify($pass, $s['password'])) {

            if (($s['status'] ?? 'active') === 'suspended') {

                log_audit(
                    $conn,
                    "Blocked Login",
                    "Suspended student attempted login. Student ID: " . $s['id']
                );

                $error = "Your account has been suspended. Please contact the administrator.";

            } else {

                $_SESSION['role'] = 'student';
                $_SESSION['student_id'] = $s['id'];
                $_SESSION['name'] = $s['name'];

                log_audit($conn, "Login", "Student logged in successfully.");

                header("Location: user.php");
                exit();
            }
        }
    }

    $error = "Invalid credentials or unapproved account.";
   
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
</head>

<body style="min-height:100vh; display:flex; align-items:center; justify-content:center;">

	<div class="card" style="max-width:420px; width:94%; text-align:center;">

    <img src="sjb_logo.jpg"
         alt="SJB Logo"
         style="width:140px;height:140px;object-fit:cover;border-radius:50%;border:4px solid #facc15;display:block;margin:0 auto 20px auto;box-shadow:0 6px 16px rgba(0,0,0,.35);">

        <h1 style="color:#facc15; margin-bottom:6px;">
            SJB Grading System
        </h1>
        
        <p style="color:#d1d5db;">
            Student Grade and Performance Management System
        </p>

        <form method="POST" style="display:grid; gap:12px; margin-top:22px;">

            <input
                type="text"
                name="email"
                placeholder="Email or Username"
                required
            >

            <input
                type="password"
                name="password"
                placeholder="Password"
                required
            >

            <button class="btn btn-yellow">
                Login
            </button>

        </form>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-top:14px;">
                <?= safe($error) ?>
            </div>
        <?php endif; ?>

        <p>
            Don't have an account?
            <a href="register.php" style="color:#facc15;">
                Register here
            </a>
        </p>

        <p style="margin-top:8px;">
            <a href="forgot_password.php" style="color:#facc15; font-weight:bold;">
                Forgot Password?
            </a>
        </p>

    </div>

</body>
</html>