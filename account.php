<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_once 'audit_helper.php';
require_login(['admin', 'teacher', 'student']);

$message = "";
$error = "";
$role = $_SESSION['role'] ?? '';
$tab = $_GET['tab'] ?? 'profile';

if (!in_array($tab, ['profile','password','reset','audit'])) $tab = 'profile';
if ($role !== 'admin' && in_array($tab, ['reset','audit'])) $tab = 'profile';

function reload_account_data($conn, $role) {
    if ($role === 'student') {
        $id = intval($_SESSION['student_id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM students WHERE id=? LIMIT 1");
    } else {
        $id = intval($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

$user_data = reload_account_data($conn, $role);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === "update_profile") {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile_number'] ?? '');
        $ecp = trim($_POST['emergency_contact_person'] ?? '');
        $ecn = trim($_POST['emergency_contact_number'] ?? '');

        if ($name === '' || $email === '') {
            $error = "Name and email are required.";
        } else {
            if ($role === 'student') {
                $id = intval($_SESSION['student_id'] ?? 0);
                $stmt = $conn->prepare("UPDATE students SET name=?, email=?, mobile_number=?, emergency_contact_person=?, emergency_contact_number=? WHERE id=?");
            } else {
                $id = intval($_SESSION['user_id'] ?? 0);
                $stmt = $conn->prepare("UPDATE users SET name=?, email=?, mobile_number=?, emergency_contact_person=?, emergency_contact_number=? WHERE id=?");
            }

            $stmt->bind_param("sssssi", $name, $email, $mobile, $ecp, $ecn, $id);

            if ($stmt->execute()) {
                $_SESSION['name'] = $name;
                $message = "Profile updated successfully.";
                if (function_exists('log_audit')) log_audit($conn, "Update Profile", "User updated own profile information.");
                $user_data = reload_account_data($conn, $role);
            } else {
                $error = "Error: " . $stmt->error;
            }
        }
        $tab = "profile";
    }

    if ($action === "change_password") {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($role === 'student') {
            $id = intval($_SESSION['student_id'] ?? 0);
            $stmt = $conn->prepare("SELECT password FROM students WHERE id=? LIMIT 1");
        } else {
            $id = intval($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare("SELECT password FROM users WHERE id=? LIMIT 1");
        }

        $stmt->bind_param("i", $id);
        $stmt->execute();
        $password_data = $stmt->get_result()->fetch_assoc();

        if (!$password_data || !password_verify($current_password, $password_data['password'])) {
            $error = "Current password is incorrect.";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);

            if ($role === 'student') {
                $stmt = $conn->prepare("UPDATE students SET password=? WHERE id=?");
            } else {
                $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            }

            $stmt->bind_param("si", $hashed, $id);

            if ($stmt->execute()) {
                $message = "Password changed successfully.";
                if (function_exists('log_audit')) log_audit($conn, "Change Password", "User changed own password.");
            } else {
                $error = "Error: " . $stmt->error;
            }
        }
        $tab = "password";
    }

    if ($action === "admin_reset_password" && $role === 'admin') {
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
            $stmt = $account_type === 'user'
                ? $conn->prepare("UPDATE users SET password=? WHERE id=?")
                : $conn->prepare("UPDATE students SET password=? WHERE id=?");
            $stmt->bind_param("si", $hashed, $account_id);

            if ($stmt->execute()) {
                $message = "Password reset successfully.";
                if (function_exists('log_audit')) log_audit($conn, "Reset Password", "Reset password for $account_type account ID $account_id.");
            } else {
                $error = "Error: " . $stmt->error;
            }
        }
        $tab = "reset";
    }
}

$filter_user = $_GET['user_role'] ?? '';
$filter_action = $_GET['audit_action'] ?? '';
$filter_date = $_GET['date'] ?? '';
$logs = null;
$reset_users = null;
$reset_students = null;

if ($role === 'admin') {
    $reset_users = $conn->query("SELECT id, name, email, role FROM users ORDER BY role, name");
    $reset_students = $conn->query("SELECT id, student_no, name, email, course, section FROM students ORDER BY course, section, name");

    $query = "SELECT * FROM audit_trail WHERE 1";
    $params = [];
    $types = "";

    if ($filter_user !== '') {
        $query .= " AND user_role=?";
        $params[] = $filter_user;
        $types .= "s";
    }

    if ($filter_action !== '') {
        $query .= " AND action LIKE ?";
        $params[] = "%" . $filter_action . "%";
        $types .= "s";
    }

    if ($filter_date !== '') {
        $query .= " AND DATE(created_at)=?";
        $params[] = $filter_date;
        $types .= "s";
    }

    $query .= " ORDER BY created_at DESC LIMIT 300";

    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $logs = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Account</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    
   
<?php nav_bar(); ?>

<div class="container">
    <h1>Account</h1>

    <?php if ($message): ?><div class="alert alert-success"><?= safe($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= safe($error) ?></div><?php endif; ?>

    <div class="quick-buttons no-print">
        <a class="btn <?= $tab === 'profile' ? 'btn-yellow' : 'btn-gray' ?>" href="account.php?tab=profile">Profile</a>
        <a class="btn <?= $tab === 'password' ? 'btn-yellow' : 'btn-gray' ?>" href="account.php?tab=password">Change Password</a>
        <?php if ($role === 'admin'): ?>
            <a class="btn <?= $tab === 'reset' ? 'btn-yellow' : 'btn-gray' ?>" href="account.php?tab=reset">Reset Passwords</a>
            <a class="btn <?= $tab === 'audit' ? 'btn-yellow' : 'btn-gray' ?>" href="account.php?tab=audit">Audit Trail</a>
        <?php endif; ?>
    </div>

    <br>

    <?php if ($tab === 'profile'): ?>
        <div class="grid grid-2">
            <div class="card">
                <h1 style="color:#facc15; text-align:center;">👤</h1>
                <h2 style="text-align:center;"><?= safe($_SESSION['name'] ?? 'User') ?></h2>
                <p style="text-align:center;"><?= status_badge($role ?: 'User') ?></p>
                <hr>
                <p><b>Name:</b> <?= safe($user_data['name'] ?? '') ?></p>
                <p><b>Email:</b> <?= safe($user_data['email'] ?? '') ?></p>
                <p><b>Mobile:</b> <?= safe($user_data['mobile_number'] ?? '') ?></p>
                <p><b>Emergency Contact:</b> <?= safe($user_data['emergency_contact_person'] ?? '') ?></p>
                <p><b>Emergency Number:</b> <?= safe($user_data['emergency_contact_number'] ?? '') ?></p>
                <?php if ($role === 'student'): ?>
                    <p><b>Student No.:</b> <?= safe($user_data['student_no'] ?? '') ?></p>
                    <p><b>Course/Section:</b> <?= safe($user_data['course'] ?? '') ?> <?= safe($user_data['section'] ?? '') ?></p>
                <?php endif; ?>
                <a href="logout.php" class="btn btn-red">Logout</a>
            </div>

            <form method="POST" class="card grid">
                <h2 style="color:#facc15;">Edit Profile</h2>
                <input type="hidden" name="action" value="update_profile">
                <input name="name" value="<?= safe($user_data['name'] ?? '') ?>" placeholder="Full Name" required>
                <input type="email" name="email" value="<?= safe($user_data['email'] ?? '') ?>" placeholder="Email" required>
                <input name="mobile_number" value="<?= safe($user_data['mobile_number'] ?? '') ?>" placeholder="Mobile Number">
                <input name="emergency_contact_person" value="<?= safe($user_data['emergency_contact_person'] ?? '') ?>" placeholder="Emergency Contact Person">
                <input name="emergency_contact_number" value="<?= safe($user_data['emergency_contact_number'] ?? '') ?>" placeholder="Emergency Contact Number">
                <button class="btn btn-yellow">Save Profile</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'password'): ?>
        <form method="POST" class="card grid grid-4">
            <h2 style="color:#facc15; grid-column:1/-1;">Change Password</h2>
            <input type="hidden" name="action" value="change_password">
            <input type="password" name="current_password" placeholder="Current Password" required>
            <input type="password" name="new_password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
            <button class="btn btn-yellow">Update Password</button>
        </form>
    <?php endif; ?>

    <?php if ($tab === 'reset' && $role === 'admin'): ?>
        <div class="grid grid-2">
            <div class="card">
                <h2 style="color:#facc15;">Reset Admin / Teacher Password</h2>
                <form method="POST" class="grid">
                    <input type="hidden" name="action" value="admin_reset_password">
                    <input type="hidden" name="account_type" value="user">
                    <select name="account_id" required>
                        <option value="">Select admin or teacher</option>
                        <?php while($u = $reset_users->fetch_assoc()): ?>
                            <option value="<?= $u['id'] ?>"><?= safe($u['name']) ?> - <?= safe($u['email']) ?> (<?= safe($u['role']) ?>)</option>
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
                    <input type="hidden" name="action" value="admin_reset_password">
                    <input type="hidden" name="account_type" value="student">
                    <select name="account_id" required>
                        <option value="">Select student</option>
                        <?php while($s = $reset_students->fetch_assoc()): ?>
                            <option value="<?= $s['id'] ?>"><?= safe($s['student_no']) ?> - <?= safe($s['name']) ?> (<?= safe($s['course']) ?> <?= safe($s['section']) ?>)</option>
                        <?php endwhile; ?>
                    </select>
                    <input type="password" name="new_password" placeholder="New Password" required>
                    <input type="password" name="confirm_password" placeholder="Re-enter New Password" required>
                    <button class="btn btn-yellow">Reset Password</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'audit' && $role === 'admin'): ?>
        <div class="card">
            <h1 style="color:#facc15;">Audit Trail</h1>
            <p style="color:#d1d5db;">View system activity logs, user actions, and login records.</p>
            <form method="GET" class="grid grid-4 no-print">
                <input type="hidden" name="tab" value="audit">
                <select name="user_role">
                    <option value="">All Roles</option>
                    <option value="admin" <?= $filter_user === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="teacher" <?= $filter_user === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                    <option value="student" <?= $filter_user === 'student' ? 'selected' : '' ?>>Student</option>
                </select>
                <input type="text" name="audit_action" value="<?= safe($filter_action) ?>" placeholder="Search action">
                <input type="date" name="date" value="<?= safe($filter_date) ?>">
                <button class="btn btn-yellow">Filter</button>
                <a href="account.php?tab=audit" class="btn btn-gray">Clear</a>
                <button type="button" onclick="window.print()" class="btn btn-blue">Print</button>
            </form>
        </div>
        <br>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs && $logs->num_rows > 0): ?>
                        <?php while($log = $logs->fetch_assoc()): ?>
                            <tr>
                                <td data-label="Date & Time"><?= safe($log['created_at']) ?></td>
                                <td data-label="User"><?= safe($log['user_name']) ?></td>
                                <td data-label="Role"><?= safe($log['user_role']) ?></td>
                                <td data-label="Action"><?= safe($log['action']) ?></td>
                                <td data-label="Description"><?= safe($log['description']) ?></td>
                                <td data-label="IP Address"><?= safe($log['ip_address']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center;">No audit trail records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
