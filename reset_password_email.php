<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_once 'audit_helper.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$message = "";
$error = "";
$valid = false;
$reset_data = null;

if ($token !== '') {
    $stmt = $conn->prepare(
        "SELECT *
        FROM password_resets
        WHERE token=?
        AND used_at IS NULL
        AND expires_at > NOW()
        LIMIT 1"
    );

    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $reset_data = $result->fetch_assoc();
        $valid = true;
    } else {
        $error = "Invalid or expired password reset link.";
    }
} else {
    $error = "Missing reset token.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $valid) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);

        $account_type = $reset_data['account_type'];
        $account_id = intval($reset_data['account_id']);

        if ($account_type === 'user') {
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        } else {
            $stmt = $conn->prepare("UPDATE students SET password=? WHERE id=?");
        }

        $stmt->bind_param("si", $hashed, $account_id);

        if ($stmt->execute()) {
            $stmt = $conn->prepare("UPDATE password_resets SET used_at=NOW() WHERE token=?");
            $stmt->bind_param("s", $token);
            $stmt->execute();

            if (function_exists('log_audit')) {
                log_audit($conn, "Email Password Reset", "Password reset completed through email link for $account_type account ID $account_id.");
            }

            $message = "Password reset successfully. You may now login.";
            $valid = false;
        } else {
            $error = "Unable to reset password.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <link rel="stylesheet" href="style.css">
</head>
<body style="min-height:100vh; display:flex; align-items:center; justify-content:center;">
<div class="card" style="max-width:460px; width:94%;">
    <h1 style="color:#facc15; text-align:center;">Reset Password</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
        <p style="text-align:center;">
            <a href="index.php" class="btn btn-yellow">Go to Login</a>
        </p>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= safe($error) ?></div>
    <?php endif; ?>

    <?php if ($valid): ?>
        <form method="POST" class="grid">
            <input type="hidden" name="token" value="<?= safe($token) ?>">
            <input type="password" name="new_password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Re-enter New Password" required>
            <button class="btn btn-yellow">Reset Password</button>
        </form>
    <?php endif; ?>

    <p style="text-align:center;">
        <a href="index.php" style="color:#facc15;">Back to Login</a>
    </p>
</div>
</body>
</html>
