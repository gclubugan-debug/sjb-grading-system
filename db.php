<?php
$conn = new mysqli(
    "sql310.byetcluster.com",
    "if0_41976731",
    "",
    "if0_41976731_webgrading_system"
);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>