<?php
session_start();

require_once 'db.php';
require_once 'helpers.php';
require_once 'audit_helper.php';
require_once 'smtp_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$message = "";
$error = "";

function send_reset_email($to, $name, $reset_link)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to, $name);

        $mail->isHTML(true);
        $mail->Subject = "SJB Online Grading System Password Reset";

        $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safe_link = htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8');

        $mail->Body = "
            <div style='font-family:Arial,sans-serif; line-height:1.6; color:#111;'>
                <h2>Password Reset Request</h2>
                <p>Hello {$safe_name},</p>
                <p>You requested to reset your password.</p>
                <p>
                    <a href='{$safe_link}'
                       style='background:#facc15; color:#000; padding:10px 16px; text-decoration:none; border-radius:6px; font-weight:bold;'>
                        Reset Password
                    </a>
                </p>
                <p>This link will expire in 1 hour.</p>
                <p>If you did not request this, please ignore this email.</p>
                <p>SJB Online Grading System</p>
            </div>
        ";

        $mail->AltBody =
            "Hello $name,\n\n" .
            "You requested to reset your password.\n\n" .
            "Reset your password using this link:\n" .
            "$reset_link\n\n" .
            "This link will expire in 1 hour.\n\n" .
            "If you did not request this, please ignore this email.\n\n" .
            "SJB Online Grading System";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email'] ?? '');

    $account_type = "";
    $account_id = 0;
    $account_name = "";

    $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user_result = $stmt->get_result();

    if ($user_result && $user_result->num_rows > 0) {
        $user = $user_result->fetch_assoc();
        $account_type = "user";
        $account_id = intval($user['id']);
        $account_name = $user['name'];
    } else {
        $stmt = $conn->prepare("SELECT id, name, email FROM students WHERE email=? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $student_result = $stmt->get_result();

        if ($student_result && $student_result->num_rows > 0) {
            $student = $student_result->fetch_assoc();
            $account_type = "student";
            $account_id = intval($student['id']);
            $account_name = $student['name'];
        }
    }

    if ($account_id > 0) {
        $token = bin2hex(random_bytes(32));
        $expires_at = date("Y-m-d H:i:s", time() + 3600);

        $stmt = $conn->prepare(
            "INSERT INTO password_resets
            (account_type, account_id, email, token, expires_at)
            VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->bind_param("sisss", $account_type, $account_id, $email, $token, $expires_at);

        if ($stmt->execute()) {
            $reset_link = rtrim(SYSTEM_BASE_URL, "/") . "/reset_password_email.php?token=" . urlencode($token);

            $sent = send_reset_email($email, $account_name, $reset_link);

            if (function_exists('log_audit')) {
                log_audit($conn, "Request Password Reset", "Password reset link requested for $account_type account ID $account_id.");
            }

            if ($sent) {
                $message = "If the email exists in the system, a password reset link has been sent.";
            } else {
                $error = "Unable to send email. Please check PHPMailer folder, Brevo SMTP credentials, and sender verification.";
            }
        } else {
            $error = "Unable to create password reset request.";
        }
    } else {
        $message = "If the email exists in the system, a password reset link has been sent.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="style.css">
</head>
<body style="min-height:100vh; display:flex; align-items:center; justify-content:center;">
<div class="card" style="max-width:460px; width:94%;">
    <h1 style="color:#facc15; text-align:center;">Forgot Password</h1>
    <p style="text-align:center; color:#d1d5db;">Enter your registered email address.</p>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= safe($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="grid">
        <input type="email" name="email" placeholder="Registered Email" required>
        <button class="btn btn-yellow">Send Reset Link</button>
    </form>

    <p style="text-align:center;">
        <a href="index.php" style="color:#facc15;">Back to Login</a>
    </p>
</div>
</body>
</html>
