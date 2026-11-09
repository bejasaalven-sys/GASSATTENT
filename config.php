<?php
/**
 * GASATTEND - configuration and shared helpers.
 * Every page does:  require __DIR__ . '/config.php';
 */
declare(strict_types=1);

/* ---------- 1. Settings (edit these for your environment) ---------- */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'gasattend';
const DB_USER = 'root';
const DB_PASS = '';              // XAMPP default is a blank password
const APP_NAME = 'gasattend';
const APP_VERSION = '1.1.0';
const APP_TIMEZONE = 'Asia/Manila';
const PER_PAGE = 10;             // rows per page in lists
const MAX_PHOTO_BYTES = 2097152; // 2 MB
const WORK_START = '09:00';      // time-in after this is suggested as "Late" in the attendance form

date_default_timezone_set(APP_TIMEZONE);

/* ---------- 2. Session + basic security headers ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/icons.php';

/* ---------- 3. Database ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

/* ---------- 4. Output / request helpers ---------- */
/** Escape any value for safe HTML output. */
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/** Trimmed string from $_POST / $_GET style arrays. */
function input(array $source, string $key, string $default = ''): string {
    $v = $source[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function valid_date(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

function valid_time(string $t): bool {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

/* ---------- 5. Flash messages (shown as toasts) ---------- */
function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function take_flash(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- 6. CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your form expired. Go back, refresh the page and try again.');
    }
}

/* ---------- 7. Authentication + role based access ---------- */
const ROLE_LABELS = ['admin' => 'Administrator', 'manager' => 'Manager', 'secretary' => 'Secretary'];
const ROLE_PERMISSIONS = [
    'admin'     => ['employees.view', 'employees.write', 'employees.archive', 'employees.purge', 'attendance.write', 'attendance.delete', 'reports.view', 'users.manage'],
    'manager'   => ['employees.view', 'employees.write', 'employees.archive', 'attendance.write', 'attendance.delete', 'reports.view'],
    'secretary' => ['employees.view', 'attendance.write', 'reports.view'],
];

function logged_in(): bool {
    return isset($_SESSION['user_id']);
}

/** Current user row (re-checked against the database so disabled accounts are signed out). */
function current_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    if (!logged_in()) return $user = null;
    $s = db()->prepare('SELECT id, username, email, full_name, role, active FROM users WHERE id = ?');
    $s->execute([(int)$_SESSION['user_id']]);
    $row = $s->fetch();
    if (!$row || (int)$row['active'] !== 1) {
        $_SESSION = [];
        return $user = null;
    }
    return $user = $row;
}

function require_login(): array {
    $u = null;
    try { $u = current_user(); } catch (Throwable $e) { /* DB unavailable */ }
    if (!$u) {
        if (session_status() === PHP_SESSION_ACTIVE) { flash('Please sign in to continue.', 'info'); }
        redirect('login.php');
    }
    return $u;
}

function can(string $permission): bool {
    $u = current_user();
    return $u !== null && in_array($permission, ROLE_PERMISSIONS[$u['role']] ?? [], true);
}

function require_can(string $permission): void {
    if (!can($permission)) {
        flash('You do not have permission to do that.', 'error');
        redirect('index.php');
    }
}

/* ---------- 8. Attendance / formatting helpers ---------- */
const STATUSES = ['Present', 'Late', 'Absent', 'On Leave', 'Rest Day'];
const EMPLOYMENT_TYPES = ['Full-time', 'Part-time', 'Contract', 'Intern'];

function status_slug(string $status): string {
    $map = ['Present' => 'present', 'Late' => 'late', 'Absent' => 'absent', 'On Leave' => 'leave', 'Rest Day' => 'rest'];
    return $map[$status] ?? 'present';
}

function status_badge(string $status): string {
    return '<span class="badge ' . status_slug($status) . '">' . h($status) . '</span>';
}

function fmt_time($time): string {
    return $time ? date('h:i A', strtotime((string)$time)) : '—';
}

function fmt_date($date, string $format = 'M j, Y'): string {
    return $date ? date($format, strtotime((string)$date)) : '—';
}

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '?', 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function photo_url(?string $file): ?string {
    return $file ? 'uploads/' . rawurlencode($file) : null;
}

/** Round avatar: photo if present, otherwise initials. */
function avatar_html(string $name, ?string $photo = null, string $class = ''): string {
    $url = photo_url($photo);
    $inner = $url ? '<img src="' . h($url) . '" alt="">' : h(initials($name));
    return '<span class="avatar ' . h($class) . '">' . $inner . '</span>';
}

function next_employee_no(): string {
    $max = 0;
    foreach (db()->query('SELECT employee_no FROM employees')->fetchAll(PDO::FETCH_COLUMN) as $no) {
        if (preg_match('/^GS-(\d+)$/', (string)$no, $m)) $max = max($max, (int)$m[1]);
    }
    return sprintf('GS-%03d', $max + 1);
}

/* ---------- 9. Pagination ---------- */
function paginate(int $total, int $page, int $perPage = PER_PAGE): array {
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per' => $perPage, 'total' => $total];
}

function render_pager(array $p, array $query = []): string {
    if ($p['pages'] <= 1) {
        return '<div class="pager"><span>Showing ' . (int)$p['total'] . ' record(s)</span></div>';
    }
    $link = function (int $n, string $label) use ($query): string {
        return '<a href="?' . h(http_build_query(array_merge($query, ['page' => $n]))) . '">' . $label . '</a>';
    };
    $out = '<div class="pager"><span>Page ' . $p['page'] . ' of ' . $p['pages'] . ' · ' . (int)$p['total'] . ' record(s)</span><div class="pages">';
    if ($p['page'] > 1) $out .= $link($p['page'] - 1, icon('chevron-left', 16));
    $start = max(1, $p['page'] - 2);
    $end = min($p['pages'], $p['page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $out .= $i === $p['page'] ? '<span class="cur">' . $i . '</span>' : $link($i, (string)$i);
    }
    if ($p['page'] < $p['pages']) $out .= $link($p['page'] + 1, icon('chevron-right', 16));
    return $out . '</div></div>';
}

/* ---------- 10. Employee photo upload ---------- */
/**
 * Validates and stores an uploaded photo. Returns the new file name,
 * or null when no file was chosen. Throws RuntimeException with a
 * user-friendly message when the file is not acceptable.
 */
function save_employee_photo(array $file): ?string {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('The photo could not be uploaded. Try a smaller image.');
    if ((int)$file['size'] > MAX_PHOTO_BYTES) throw new RuntimeException('Photo must be 2 MB or smaller.');
    $info = @getimagesize($file['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) throw new RuntimeException('Photo must be a JPG, PNG or WebP image.');
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('The uploads folder is not writable.');
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('The photo could not be saved.');
    return $name;
}

function delete_employee_photo(?string $file): void {
    if (!$file) return;
    $path = __DIR__ . '/uploads/' . basename($file);
    if (is_file($path)) @unlink($path);
}

/* ---------- 11. Simple login throttle (per browser session) ---------- */
function login_locked_seconds(): int {
    $t = $_SESSION['login_lock'] ?? 0;
    return max(0, (int)$t - time());
}

function login_failed(): void {
    $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
    if ($_SESSION['login_fails'] >= 5) {
        $_SESSION['login_lock'] = time() + 60;
        $_SESSION['login_fails'] = 0;
    }
}

function login_succeeded(): void {
    unset($_SESSION['login_fails'], $_SESSION['login_lock']);
}
