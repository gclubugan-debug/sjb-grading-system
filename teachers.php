<?php
session_start();
require_once 'audit_helper.php';
require_once 'db.php';
require_once 'helpers.php';
require_login(['admin']);

$message = "";
$error = "";
$edit_user = null;

if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND role IN ('admin','teacher') LIMIT 1");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_user = $stmt->get_result()->fetch_assoc();
}

function count_active_admins($conn) {
    $result = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='admin' AND (status='active' OR status IS NULL)");
    $row = $result->fetch_assoc();
    return intval($row['total'] ?? 0);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $mobile = trim($_POST['mobile_number']);
        $ecp = trim($_POST['emergency_contact_person']);
        $ecn = trim($_POST['emergency_contact_number']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        if (!in_array($role, ['admin', 'teacher'])) {
            $error = "Invalid role selected.";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (name,email,mobile_number,emergency_contact_person,emergency_contact_number,password,role,status) VALUES (?,?,?,?,?,?,?,'active')");
            $stmt->bind_param("sssssss", $name, $email, $mobile, $ecp, $ecn, $password, $role);

            if ($stmt->execute()) {
                $message = ucfirst($role) . " account added successfully.";

                if (function_exists('log_audit')) {
                    log_audit($conn, "Add User", "Added $role account: $name with email $email.");
                }
            } else {
                $error = "Error: " . $stmt->error;
            }
        }
    }

    if ($action === 'update_user') {
        $user_id = intval($_POST['user_id']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $mobile = trim($_POST['mobile_number']);
        $ecp = trim($_POST['emergency_contact_person']);
        $ecn = trim($_POST['emergency_contact_number']);
        $role = $_POST['role'];

        if (!in_array($role, ['admin', 'teacher'])) {
            $error = "Invalid role selected.";
        } else {
            $current_stmt = $conn->prepare("SELECT role, status FROM users WHERE id=? LIMIT 1");
            $current_stmt->bind_param("i", $user_id);
            $current_stmt->execute();
            $current = $current_stmt->get_result()->fetch_assoc();

            if (!$current) {
                $error = "User not found.";
            } elseif ($current['role'] === 'admin' && $role !== 'admin' && count_active_admins($conn) <= 1 && (($current['status'] ?? 'active') === 'active')) {
                $error = "You cannot change the last active admin into a teacher.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET name=?, email=?, mobile_number=?, emergency_contact_person=?, emergency_contact_number=?, role=? WHERE id=? AND role IN ('admin','teacher')");
                $stmt->bind_param("ssssssi", $name, $email, $mobile, $ecp, $ecn, $role, $user_id);

                if ($stmt->execute()) {
                    $message = "User account updated successfully.";

                    if (function_exists('log_audit')) {
                        log_audit($conn, "Update User", "Updated user ID $user_id. New role: $role.");
                    }
                } else {
                    $error = "Error: " . $stmt->error;
                }
            }
        }
    }

    if ($action === 'update_status') {
        $user_id = intval($_POST['user_id']);
        $status = $_POST['status'];

        if (in_array($status, ['active','suspended'])) {
            $current_stmt = $conn->prepare("SELECT role, status FROM users WHERE id=? LIMIT 1");
            $current_stmt->bind_param("i", $user_id);
            $current_stmt->execute();
            $current = $current_stmt->get_result()->fetch_assoc();

            if (!$current) {
                $error = "User not found.";
            } elseif ($current['role'] === 'admin' && $status === 'suspended' && (($current['status'] ?? 'active') === 'active') && count_active_admins($conn) <= 1) {
                $error = "You cannot suspend the last active admin account.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET status=? WHERE id=? AND role IN ('admin','teacher')");
                $stmt->bind_param("si", $status, $user_id);

                if ($stmt->execute()) {
                    $message = "User status updated.";

                    if (function_exists('log_audit')) {
                        log_audit($conn, "Update User Status", "Updated user ID $user_id status to $status.");
                    }
                } else {
                    $error = "Error: " . $stmt->error;
                }
            }
        }
    }

    if ($action === 'delete_user') {
        $user_id = intval($_POST['user_id']);

        $current_stmt = $conn->prepare("SELECT role, status FROM users WHERE id=? LIMIT 1");
        $current_stmt->bind_param("i", $user_id);
        $current_stmt->execute();
        $current = $current_stmt->get_result()->fetch_assoc();

        if (!$current) {
            $error = "User not found.";
        } elseif ($current['role'] === 'admin' && (($current['status'] ?? 'active') === 'active') && count_active_admins($conn) <= 1) {
            $error = "You cannot delete the last active admin account.";
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND role IN ('admin','teacher')");
            $stmt->bind_param("i", $user_id);

            if ($stmt->execute()) {
                $message = "User account deleted.";

                if (function_exists('log_audit')) {
                    log_audit($conn, "Delete User", "Deleted user account ID $user_id.");
                }
            } else {
                $error = "Error: " . $stmt->error;
            }
        }
    }
}

$role_filter = $_GET['role'] ?? '';
$search = trim($_GET['search'] ?? '');

$query = "SELECT * FROM users WHERE role IN ('admin','teacher')";
$params = [];
$types = "";

if ($role_filter !== '' && in_array($role_filter, ['admin', 'teacher'])) {
    $query .= " AND role=?";
    $params[] = $role_filter;
    $types .= "s";
}

if ($search !== '') {
    $query .= " AND (name LIKE ? OR email LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$query .= " ORDER BY role, name";

$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$users = $stmt->get_result();

$admin_count = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='admin'")->fetch_assoc()['total'] ?? 0;
$teacher_count = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='teacher'")->fetch_assoc()['total'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Users Management</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php nav_bar(); ?>

<div class="container">
    <h1>Users Management</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= safe($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-2">
        <div class="card-yellow">
            <h2>Total Admins</h2>
            <h1><?= safe($admin_count) ?></h1>
        </div>

        <div class="card-yellow">
            <h2>Total Teachers</h2>
            <h1><?= safe($teacher_count) ?></h1>
        </div>
    </div>

    <br>

    <form method="POST" class="card grid grid-3">
        <h2 style="grid-column:1/-1; color:#facc15;">
            <?= $edit_user ? 'Edit User' : 'Add User' ?>
        </h2>

        <input type="hidden" name="action" value="<?= $edit_user ? 'update_user' : 'add_user' ?>">
        <?php if ($edit_user): ?>
            <input type="hidden" name="user_id" value="<?= safe($edit_user['id']) ?>">
        <?php endif; ?>

        <input name="name" placeholder="Full Name" value="<?= safe($edit_user['name'] ?? '') ?>" required>

        <input name="email" type="email" placeholder="Email" value="<?= safe($edit_user['email'] ?? '') ?>" required>

        <select name="role" required>
            <option value="teacher" <?= (($edit_user['role'] ?? '') === 'teacher') ? 'selected' : '' ?>>Teacher</option>
            <option value="admin" <?= (($edit_user['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
        </select>

        <input name="mobile_number" placeholder="Mobile Number" value="<?= safe($edit_user['mobile_number'] ?? '') ?>" required>

        <input name="emergency_contact_person" placeholder="Emergency Contact Person" value="<?= safe($edit_user['emergency_contact_person'] ?? '') ?>" required>

        <input name="emergency_contact_number" placeholder="Emergency Contact Number" value="<?= safe($edit_user['emergency_contact_number'] ?? '') ?>" required>

        <?php if (!$edit_user): ?>
            <input name="password" type="password" placeholder="Password" required>
        <?php endif; ?>

        <button class="btn btn-yellow">
            <?= $edit_user ? 'Update User' : 'Add User' ?>
        </button>

        <?php if ($edit_user): ?>
            <a href="teachers.php" class="btn btn-gray">Cancel Edit</a>
        <?php endif; ?>
    </form>

    <br>

    <form method="GET" class="card grid grid-3 no-print">
        <input name="search" value="<?= safe($search) ?>" placeholder="Search name or email">

        <select name="role">
            <option value="">All Roles</option>
            <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="teacher" <?= $role_filter === 'teacher' ? 'selected' : '' ?>>Teacher</option>
        </select>

        <button class="btn btn-yellow">Filter</button>
        <a href="teachers.php" class="btn btn-gray">Clear</a>
    </form>

    <br>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Mobile</th>
                    <th>Emergency Contact</th>
                    <th>Status</th>
                    <th>Admin Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users && $users->num_rows > 0): ?>
                    <?php while($u = $users->fetch_assoc()): ?>
                        <tr>
                            <td data-label="Name"><?= safe($u['name']) ?></td>
                            <td data-label="Email"><?= safe($u['email']) ?></td>
                            <td data-label="Role"><?= safe(ucfirst($u['role'])) ?></td>
                            <td data-label="Mobile"><?= safe($u['mobile_number']) ?></td>
                            <td data-label="Emergency Contact"><?= safe($u['emergency_contact_person']) ?> - <?= safe($u['emergency_contact_number']) ?></td>
                            <td data-label="Status"><?= safe($u['status'] ?? 'active') ?></td>
                            <td data-label="Admin Actions">
                                <div class="quick-buttons">
                                    <a class="btn btn-blue" href="teachers.php?edit=<?= $u['id'] ?>">Edit</a>

                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="hidden" name="action" value="update_status">

                                        <select name="status" onchange="this.form.submit()">
                                            <option value="active" <?= (($u['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                                            <option value="suspended" <?= (($u['status'] ?? 'active') === 'suspended') ? 'selected' : '' ?>>Suspended</option>
                                        </select>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user account?');">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <button class="btn btn-red">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align:center;">No users found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
