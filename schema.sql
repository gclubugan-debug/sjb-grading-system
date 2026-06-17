CREATE DATABASE IF NOT EXISTS webgrading_system;
USE webgrading_system;

DROP TABLE IF EXISTS grade_items;
DROP TABLE IF EXISTS student_subjects;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS pending_requests;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    mobile_number VARCHAR(30) NULL,
    emergency_contact_person VARCHAR(100) NULL,
    emergency_contact_number VARCHAR(30) NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'teacher') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_no VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    mobile_number VARCHAR(30) NOT NULL,
    emergency_contact_person VARCHAR(100) NOT NULL,
    emergency_contact_number VARCHAR(30) NOT NULL,
    course VARCHAR(100) NOT NULL,
    section VARCHAR(50) NOT NULL,
    year_level VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pending_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_no VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    mobile_number VARCHAR(30) NOT NULL,
    emergency_contact_person VARCHAR(100) NOT NULL,
    emergency_contact_number VARCHAR(30) NOT NULL,
    course VARCHAR(100) NOT NULL,
    section VARCHAR(50) NOT NULL,
    year_level VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_code VARCHAR(50) NOT NULL UNIQUE,
    subject_name VARCHAR(100) NOT NULL,
    units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
    teacher_id INT NULL,

    FOREIGN KEY (teacher_id)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE student_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    academic_term ENUM(
        '1st Term',
        '2nd Term',
        '3rd Term'
    ) NOT NULL,
    school_year VARCHAR(20) DEFAULT '2025-2026',

    FOREIGN KEY (student_id)
        REFERENCES students(id)
        ON DELETE CASCADE,

    FOREIGN KEY (subject_id)
        REFERENCES subjects(id)
        ON DELETE CASCADE,

    UNIQUE KEY unique_student_subject_term (
        student_id,
        subject_id,
        academic_term,
        school_year
    )
);

CREATE TABLE grade_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    academic_term ENUM(
        '1st Term',
        '2nd Term',
        '3rd Term'
    ) NOT NULL,
    grading_period ENUM(
        'Prelim',
        'Midterm',
        'Finals'
    ) NOT NULL,
    component ENUM(
        'Exam',
        'Project',
        'Quiz',
        'Attendance'
    ) NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    score DECIMAL(6,2) NOT NULL,
    total_score DECIMAL(6,2) NOT NULL DEFAULT 100,
    encoded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (student_id)
        REFERENCES students(id)
        ON DELETE CASCADE,

    FOREIGN KEY (subject_id)
        REFERENCES subjects(id)
        ON DELETE CASCADE,

    FOREIGN KEY (encoded_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

INSERT INTO users (
    name,
    email,
    mobile_number,
    emergency_contact_person,
    emergency_contact_number,
    password,
    role
)
VALUES
(
    'Administrator',
    'admin',
    '09000000000',
    'School Office',
    '09000000001',
    '$2y$10$JPcPZ0.JlRIPN4oWyB2CteMkIkjpp34Hg6q0LAgE/7c9h82h1Fj8u',
    'admin'
),
(
    'Sample Teacher',
    'teacher@sjb.edu',
    '09111111111',
    'Teacher Emergency Contact',
    '09111111112',
    '$2y$10$p3iznLZwOnup63yrvpjfseIV/TKgrPDmN3a5Wg35MP7TW7n2Zbtfu',
    'teacher'
);

INSERT INTO subjects (
    subject_code,
    subject_name,
    units,
    teacher_id
)
VALUES
(
    'PDEV101',
    'Personality Development',
    3.0,
    2
),
(
    'GE109',
    'Rizal Life and Works',
    3.0,
    2
),
(
    'IT203',
    'Event Driven Programming',
    3.0,
    2
),
(
    'CAP101',
    'IT Capstone Project 1',
    3.0,
    2
);

ALTER TABLE students
ADD COLUMN status ENUM('active', 'suspended')
NOT NULL DEFAULT 'active';

ALTER TABLE users
ADD COLUMN status ENUM('active', 'suspended')
NOT NULL DEFAULT 'active';