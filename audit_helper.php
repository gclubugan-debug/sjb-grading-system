<?php
function log_audit($conn, $action, $description) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $user_id = $_SESSION['user_id'] ?? ($_SESSION['student_id'] ?? null);
    $user_role = $_SESSION['role'] ?? 'guest';
    $user_name = $_SESSION['name'] ?? 'Unknown User';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $conn->prepare("
        INSERT INTO audit_trail
        (user_id, user_role, user_name, action, description, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "issssss",
        $user_id,
        $user_role,
        $user_name,
        $action,
        $description,
        $ip_address,
        $user_agent
    );

    $stmt->execute();
}
?>
