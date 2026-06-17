<?php
session_start();
require_once 'db.php';
require_once 'audit_helper.php';

log_audit($conn, "Logout", "User logged out.");

session_unset();
session_destroy();

header('Location: index.php');
exit();
?>
