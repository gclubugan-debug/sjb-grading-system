<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_login(['admin', 'teacher', 'student']);

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } else {
        if ($_SESSION['role'] === 'student') {
            $id = intval($_SESSION['student_id']);
            $stmt = $conn->prepare("SELECT password FROM students WHERE id=?");
            $stmt->bind_param("i", $id);
        } else {
            $id = intval($_SESSION['user_id']);
            $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
            $stmt->bind_param("i", $id);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();

            if (password_verify($current_password, $row['password'])) {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);

                if ($_SESSION['role'] === 'student') {
                    $update = $conn->prepare("UPDATE students SET password=? WHERE id=?");
                } else {
                    $update = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                }

                $update->bind_param("si", $hashed, $id);

                if ($update->execute()) {
                    $message = "Password changed successfully.";
                } else {
                    $error = "Error: " . $update->error;
                }
            } else {
                $error = "Current password is incorrect.";
            }
        } else {
            $error = "Account not found.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Change Password</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php nav_bar(); ?>
<div class="container">
    <h1>Change Password</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= safe($error) ?></div>
    <?php endif; ?>

    <div class="card" style="max-width:520px;">
        <form method="POST" class="grid">
            <input type="password" name="current_password" placeholder="Current Password" required>
            <input type="password" name="new_password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Re-enter New Password" required>
            <button class="btn btn-yellow">Change Password</button>
        </form>
    </div>
</div>
</body>
</html>
