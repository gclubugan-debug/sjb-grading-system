ALTER TABLE students 
ADD COLUMN status ENUM('active','suspended') NOT NULL DEFAULT 'active';

ALTER TABLE users 
ADD COLUMN status ENUM('active','suspended') NOT NULL DEFAULT 'active';
