-- =====================================================================
-- GASATTEND database - ONE file for everything
-- Import in phpMyAdmin: Import > choose this file > Go.
--
--   * New install ............ creates the database and all tables.
--   * Existing gasattend DB .. adds the new columns; your data is kept.
--   * Safe to import more than once.
--   * Demo data is at the very bottom (commented out) - see instructions there.
--
-- Needs XAMPP's MariaDB. On MySQL 8 remove "IF NOT EXISTS" after ADD COLUMN / ADD INDEX.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS gasattend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gasattend;

-- ---------- 1. Tables (created only if missing) ----------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(120) NOT NULL UNIQUE,            -- login name (new accounts use their email)
    email VARCHAR(120) NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    role ENUM('secretary','manager','admin') NOT NULL DEFAULT 'secretary',
    active TINYINT(1) NOT NULL DEFAULT 1,             -- 0 = disabled or waiting for admin approval
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_no VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    position VARCHAR(80) NOT NULL DEFAULT '',
    department VARCHAR(80) NOT NULL DEFAULT '',
    hire_date DATE NULL,
    employment_type ENUM('Full-time','Part-time','Contract','Intern') NOT NULL DEFAULT 'Full-time',
    photo VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,             -- 1 = Active, 0 = Inactive
    archived_at DATETIME NULL,                        -- not NULL = archived (soft deleted)
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('Present','Absent','Late','On Leave','Rest Day') NOT NULL DEFAULT 'Present',
    time_in TIME NULL,
    time_out TIME NULL,
    notes VARCHAR(500) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_attendance_user FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uniq_employee_day (employee_id, attendance_date),
    INDEX idx_attendance_date (attendance_date)
);

-- ---------- 2. Upgrade for databases made with the ORIGINAL schema (no effect on new installs) ----------
ALTER TABLE users
    MODIFY username VARCHAR(120) NOT NULL,
    ADD COLUMN IF NOT EXISTS email VARCHAR(120) NULL AFTER username,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER active;
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uniq_user_email (email);

ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS email VARCHAR(120) NULL AFTER full_name,
    ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NOT NULL DEFAULT '' AFTER email,
    ADD COLUMN IF NOT EXISTS department VARCHAR(80) NOT NULL DEFAULT '' AFTER position,
    ADD COLUMN IF NOT EXISTS hire_date DATE NULL AFTER department,
    ADD COLUMN IF NOT EXISTS employment_type ENUM('Full-time','Part-time','Contract','Intern') NOT NULL DEFAULT 'Full-time' AFTER hire_date,
    ADD COLUMN IF NOT EXISTS photo VARCHAR(255) NULL AFTER employment_type,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER active,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE employees ADD INDEX IF NOT EXISTS idx_employee_name (full_name);

-- ---------- 3. OPTIONAL demo data (fictional people) ----------
-- To use it: 1) import this file, 2) open the app and create your first account,
-- 3) delete the "/*" line and the "*/" line around this section, 4) import the file again.
/*
INSERT IGNORE INTO employees (employee_no, full_name, email, phone, position, department, hire_date, employment_type, active) VALUES
('GS-001','Maria Santos','maria.santos@example.com','0917 123 4501','Station Manager','Operations','2021-03-15','Full-time',1),
('GS-002','Juan Dela Cruz','juan.delacruz@example.com','0917 123 4502','Cashier','Sales','2022-01-10','Full-time',1),
('GS-003','Ana Reyes','ana.reyes@example.com','0917 123 4503','Attendant','Operations','2022-06-01','Full-time',1),
('GS-004','Carlos Mendoza','carlos.mendoza@example.com','0917 123 4504','Supervisor','Operations','2020-09-21','Full-time',1),
('GS-005','Sofia Garcia','sofia.garcia@example.com','0917 123 4505','Accountant','Finance','2023-02-06','Full-time',1),
('GS-006','Miguel Torres','miguel.torres@example.com','0917 123 4506','Attendant','Operations','2023-05-15','Part-time',1),
('GS-007','Isabel Ramos','isabel.ramos@example.com','0917 123 4507','Cashier','Sales','2023-08-01','Full-time',1),
('GS-008','Gabriel Cruz','gabriel.cruz@example.com','0917 123 4508','Mechanic','Maintenance','2019-11-04','Full-time',1),
('GS-009','Patricia Lim','patricia.lim@example.com','0917 123 4509','HR Officer','Administration','2021-07-19','Full-time',1),
('GS-010','Ramon Aquino','ramon.aquino@example.com','0917 123 4510','Security Guard','Security','2022-10-03','Contract',1),
('GS-011','Elena Bautista','elena.bautista@example.com','0917 123 4511','Attendant','Operations','2024-01-08','Intern',1),
('GS-012','Daniel Villanueva','daniel.villanueva@example.com','0917 123 4512','Driver','Logistics','2020-04-27','Full-time',0);

-- Ten days of attendance for every active, non-archived employee.
INSERT IGNORE INTO attendance (employee_id, attendance_date, status, time_in, time_out, notes, created_by)
SELECT t.id, t.day, t.st,
       CASE t.st WHEN 'Present' THEN SEC_TO_TIME(8*3600 + MOD(t.id*137 + t.n*53, 20)*60)
                 WHEN 'Late'    THEN SEC_TO_TIME(9*3600 + (10 + MOD(t.id*91 + t.n*17, 40))*60)
                 ELSE NULL END,
       CASE WHEN t.st IN ('Present','Late') THEN SEC_TO_TIME(17*3600 + MOD(t.id*29 + t.n*7, 30)*60) ELSE NULL END,
       '', (SELECT MIN(id) FROM users)
FROM (
    SELECT e.id, x.n, (CURDATE() - INTERVAL x.n DAY) AS day,
           CASE MOD(e.id*7 + x.n*3, 14) WHEN 0 THEN 'Late' WHEN 1 THEN 'Late' WHEN 2 THEN 'Absent'
                WHEN 3 THEN 'On Leave' WHEN 4 THEN 'Rest Day' ELSE 'Present' END AS st
    FROM employees e
    CROSS JOIN (SELECT 0 AS n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) x
    WHERE e.active = 1 AND e.archived_at IS NULL
) t;
*/
