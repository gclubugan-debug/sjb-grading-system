<?php

session_start();

require_once 'db.php';
require_once 'helpers.php';

require_login(['admin']);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id = intval($_POST['id']);
    $action = $_POST['action'];

    if ($action === "approve") {

        $stmt = $conn->prepare(
            "SELECT * FROM pending_requests WHERE id = ?"
        );

        $stmt->bind_param("i", $id);
        $stmt->execute();

        $get = $stmt->get_result();

        if ($get && $get->num_rows > 0) {

            $row = $get->fetch_assoc();

            $insert = $conn->prepare(
                "INSERT INTO students (
                    student_no,
                    name,
                    email,
                    mobile_number,
                    emergency_contact_person,
                    emergency_contact_number,
                    course,
                    section,
                    year_level,
                    password
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $insert->bind_param(
                "ssssssssss",
                $row['student_no'],
                $row['name'],
                $row['email'],
                $row['mobile_number'],
                $row['emergency_contact_person'],
                $row['emergency_contact_number'],
                $row['course'],
                $row['section'],
                $row['year_level'],
                $row['password']
            );

            $insert->execute();

            $update = $conn->prepare(
                "UPDATE pending_requests
                SET status = 'approved'
                WHERE id = ?"
            );

            $update->bind_param("i", $id);
            $update->execute();
        }

    } elseif ($action === "delete") {

        $delete = $conn->prepare(
            "DELETE FROM pending_requests WHERE id = ?"
        );

        $delete->bind_param("i", $id);
        $delete->execute();
    }
}

header("Location: admin.php");
exit();

?>