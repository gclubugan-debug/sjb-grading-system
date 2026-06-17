<?php
session_start();
require_once 'audit_helper.php';
require_once 'db.php';
require_once 'helpers.php';
require_login(['admin']);

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $account_type = $_POST['account_type'] ?? '';
    $account_id = intval($_POST['account_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($account_id <= 0 || !in_array($account_type, ['user', 'student'])) {
        $error = "Please select a valid account.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);

        if ($account_type === 'user') {
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        } else {
            $stmt = $conn->prepare("UPDATE students SET password=? WHERE id=?");
        }

        $stmt->bind_param("si", $hashed, $account_id);

        if ($stmt->execute()) {
            $message = "Password reset successfully.";
            log_audit($conn, "Reset Password", "Reset password for $account_type account ID $account_id.");
        } else {
            $error = "Error: " . $stmt->error;
        }
    }
}

$users = $conn->query("SELECT id, name, email, role FROM users ORDER BY role, name");
$students = $conn->query("SELECT id, student_no, name, email, course, section FROM students ORDER BY course, section, name");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset Passwords</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php nav_bar(); ?>
<div class="container">
    <h1>Reset Passwords</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= safe($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-2">
        <div class="card">
            <h2 style="color:#facc15;">Reset Admin / Teacher Password</h2>
            <form method="POST" class="grid">
                <input type="hidden" name="account_type" value="user">

                <select name="account_id" required>
                    <option value="">Select admin or teacher</option>
                    <?php while($u = $users->fetch_assoc()): ?>
                        <option value="<?= $u['id'] ?>">
                            <?= safe($u['name']) ?> - <?= safe($u['email']) ?> (<?= safe($u['role']) ?>)
                        </option>
                    <?php endwhile; ?>
                </select>

                <input type="password" name="new_password" placeholder="New Password" required>
                <input type="password" name="confirm_password" placeholder="Re-enter New Password" required>

                <button class="btn btn-yellow">Reset Password</button>
            </form>
        </div>

        <div class="card">
            <h2 style="color:#facc15;">Reset Student Password</h2>
            <form method="POST" class="grid">
                <input type="hidden" name="account_type" value="student">

                <select name="account_id" required>
                    <option value="">Select student</option>
                    <?php while($s = $students->fetch_assoc()): ?>
                        <option value="<?= $s['id'] ?>">
                            <?= safe($s['student_no']) ?> - <?= safe($s['name']) ?> (<?= safe($s['course']) ?> <?= safe($s['section']) ?>)
                        </option>
                    <?php endwhile; ?>
                </select>

                <input type="password" name="new_password" placeholder="New Password" required>
                <input type="password" name="confirm_password" placeholder="Re-enter New Password" required>

                <button class="btn btn-yellow">Reset Password</button>
            </form>
        </div>
    </div>

    <br>
 
</div>
</body>
</html>
