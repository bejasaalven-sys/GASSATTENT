GASATTEND - Employee Attendance & Management System (v1.1.0)
=============================================================
PHP + MySQL/MariaDB web app. Runs on XAMPP.

WHAT'S NEW IN THIS VERSION
- Login / Create Account page rebuilt to match the supplied design (tabs, show/hide password,
  strength meter, inline validation, login throttle after 5 failed tries).
- Dashboard rebuilt to match the supplied design: sidebar with sub-menus, notification bell,
  stat cards, attendance-rate ring, recent records, quick actions, status overview (Today / 7 days / Month).
- Employees: add, view (search, filters, paging), edit, activate/deactivate, archive, restore,
  permanent delete (admin, archived employees with no attendance only), profile photo upload.
- Attendance: log, edit and delete records, date navigation, Late auto-suggest, Bulk Entry page.
- Reports: detail + per-employee summary, filters, print, server-side CSV export.
- Settings: edit profile, change password; administrators manage users and roles.
- Role-based access: Administrator / Manager / Secretary (see below).
- Toast notifications, confirm dialogs, responsive layout (desktop, tablet, phone).
- Fixed: the old style.css had been overwritten by a single-page stylesheet, so the PHP pages
  had no styling. All pages now share style.css.

INSTALL (XAMPP)
1. Copy this folder to C:\xampp\htdocs\gasattend\
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Open http://localhost/phpmyadmin/ and Import database.sql. It is the only SQL file:
   it creates a new database, or upgrades an existing gasattend one without losing data.
4. Check config.php (DB_USER / DB_PASS). XAMPP default: root with a blank password.
5. Open http://localhost/gasattend/login.php
   The FIRST account you create becomes the Administrator. Later sign-ups are created as
   "Secretary" and must be approved in Settings > Users & Roles before they can sign in.
   (Accounts from the old version still sign in with their username.)
6. Optional demo data: see the commented block at the bottom of database.sql.
   setup_admin.php is no longer needed and was removed.

ROLES
  Administrator  everything, including user management and permanent delete
  Manager        employees (add/edit/archive), attendance (log/delete), reports
  Secretary      view employees, log attendance, view reports

FILES
  config.php            settings, database, sessions, permissions, CSRF, helpers
  login.php             sign in + create account        logout.php  sign out (POST)
  index.php             dashboard
  employees.php         employee list and row actions   employee_form.php  add / edit
  attendance.php        daily log + edit/delete         attendance_bulk.php  bulk entry
  reports.php           reports, print, CSV export
  settings.php          profile, password, users & roles
  help.php              help and FAQ
  layout.php            shared sidebar / topbar / toasts    icons.php  SVG icons
  style.css             all styling      app.js  all behaviour      logo.svg / favicon.svg  branding
  uploads/              employee photos (its .htaccess blocks script execution - keep it)
  database.sql          schema + upgrade + optional demo data (one file)
  frontend.html         static preview of the layouts with sample data (does not save anything)

SECURITY NOTES
  Prepared statements, output escaping, CSRF tokens on every form, hashed passwords,
  session ID regeneration on login, upload validation (type, size, random file names).
  This is still a learning prototype. Before real use: serve over HTTPS, set a database
  password, restrict DB access, and review privacy rules for employee data.

GOOD TO KNOW
  - Time zone is Asia/Manila (APP_TIMEZONE in config.php). Shift start used for the "Late"
    suggestion is WORK_START (09:00).
  - Dashboard rate = (Present + Late today) / active employees. Employee Summary rate =
    (Present + Late) / (Present + Late + Absent).
  - "Sign up with Google / Microsoft" buttons are visual only (single sign-on is not set up).
  - Photo uploads above PHP's upload_max_filesize (2 MB default in XAMPP) are rejected with a message.
  - Overnight shifts are not supported by the time-out validation.
