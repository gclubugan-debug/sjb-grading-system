<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_login(['admin']);

$filter_user = $_GET['user_role'] ?? '';
$filter_action = $_GET['action'] ?? '';
$filter_date = $_GET['date'] ?? '';

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

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$logs = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Audit Trail</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php nav_bar(); ?>

<div class="container">
    <h1>Audit Trail</h1>

    <form method="GET" class="card grid grid-4 no-print">
        <select name="user_role">
            <option value="">All Roles</option>
            <option value="admin" <?= $filter_user === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="teacher" <?= $filter_user === 'teacher' ? 'selected' : '' ?>>Teacher</option>
            <option value="student" <?= $filter_user === 'student' ? 'selected' : '' ?>>Student</option>
        </select>

        <input type="text" name="action" value="<?= safe($filter_action) ?>" placeholder="Search action">

        <input type="date" name="date" value="<?= safe($filter_date) ?>">

        <button class="btn btn-yellow">Filter</button>
        <a href="audit_trail.php" class="btn btn-gray">Clear</a>
        <button type="button" onclick="window.print()" class="btn btn-blue">Print</button>
    </form>

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
                    <tr>
                        <td colspan="6" style="text-align:center;">No audit trail records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
