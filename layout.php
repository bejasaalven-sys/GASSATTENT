<?php
/**
 * Shared application layout.
 *
 * Usage in a page:
 *   $user = require_login();
 *   render_header('Employees', 'employees');
 *   ... page markup ...
 *   render_footer();
 */
declare(strict_types=1);

/** Items for the notification bell (computed from live data). */
function notification_items(): array {
    $items = [];
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM employees e WHERE e.active = 1 AND e.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.employee_id = e.id AND a.attendance_date = ?)");
        $s->execute([date('Y-m-d')]);
        $missing = (int)$s->fetchColumn();
        if ($missing > 0) {
            $items[] = ['icon' => 'clock', 'text' => $missing . ' active employee' . ($missing === 1 ? ' has' : 's have') . ' no attendance logged today.', 'href' => 'attendance_bulk.php'];
        }
        if (can('users.manage')) {
            $pending = (int)db()->query('SELECT COUNT(*) FROM users WHERE active = 0 AND last_login_at IS NULL')->fetchColumn();
            if ($pending > 0) {
                $items[] = ['icon' => 'user-plus', 'text' => $pending . ' new account' . ($pending === 1 ? ' is' : 's are') . ' waiting for approval.', 'href' => 'settings.php?tab=users'];
            }
        }
    } catch (Throwable $e) { /* never break a page because of the bell */ }
    return $items;
}

function render_header(string $title, string $active = ''): void {
    $user = current_user();
    $notes = notification_items();
    $nav = [
        ['dashboard', 'Dashboard', 'home', 'index.php', []],
        ['employees', 'Employees', 'users', 'employees.php', []],
        ['attendance', 'Attendance', 'calendar-check', '', [['attendance', 'Daily Log', 'attendance.php'], ['attendance-bulk', 'Bulk Entry', 'attendance_bulk.php']]],
        ['reports', 'Reports', 'bar-chart', '', [['reports', 'Attendance Report', 'reports.php'], ['reports-summary', 'Employee Summary', 'reports.php?view=summary']]],
        ['settings', 'Settings', 'settings', 'settings.php', []],
        ['help', 'Help & Support', 'help', 'help.php', []],
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> | GASATTEND</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="style.css">
<script defer src="app.js"></script>
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php">
      <img src="logo.svg" alt="">
      <span><strong>gasattend</strong><small>Employee Attendance &amp;<br>Management System</small></span>
    </a>
    <nav class="nav" aria-label="Main">
<?php foreach ($nav as [$key, $label, $ico, $href, $children]): ?>
<?php if ($children): $open = strpos($active, $key) === 0; ?>
      <div class="nav-group <?= $open ? 'open' : '' ?>">
        <button type="button" class="nav-link nav-toggle" aria-expanded="<?= $open ? 'true' : 'false' ?>"><?= icon($ico) ?><span><?= h($label) ?></span><?= icon('chevron-down', 16) ?></button>
        <div class="nav-sub">
<?php foreach ($children as [$ckey, $clabel, $chref]): ?>
          <a href="<?= h($chref) ?>" class="<?= $active === $ckey ? 'active' : '' ?>"><?= h($clabel) ?></a>
<?php endforeach; ?>
        </div>
      </div>
<?php else: ?>
      <a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= h($href) ?>"><?= icon($ico) ?><span><?= h($label) ?></span></a>
<?php endif; ?>
<?php endforeach; ?>
    </nav>
    <div class="promo">
      <svg class="rocket" viewBox="0 0 64 64" aria-hidden="true"><path d="M32 4c9 7 13 17 11 30l-6 6H27l-6-6C19 21 23 11 32 4z" fill="#e8f0ff"/><circle cx="32" cy="22" r="5" fill="#2f7bff"/><path d="M21 34l-9 9 3 5 9-4zM43 34l9 9-3 5-9-4z" fill="#ff7a3d"/><path d="M28 42h8l-4 14z" fill="#ffb02e"/></svg>
      <strong>Work Smarter.<br>Track Better.</strong>
      <p>Streamline your attendance management with gasattend.</p>
      <a href="help.php">Learn More <?= icon('arrow-right', 15) ?></a>
    </div>
    <div class="sidebar-foot">v<?= h(APP_VERSION) ?></div>
  </aside>
  <div class="scrim" id="scrim"></div>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn" id="menu-toggle" type="button" aria-label="Toggle menu"><?= icon('menu', 24) ?></button>
      <form class="search" action="employees.php" method="get" role="search">
        <?= icon('search', 18) ?>
        <input type="search" name="q" placeholder="Search employees..." aria-label="Search employees">
      </form>
      <div class="top-spacer"></div>
      <div class="dropdown">
        <button class="icon-btn" type="button" data-dropdown aria-label="Notifications"><?= icon('bell', 24) ?><?php if ($notes): ?><span class="badge-dot"><?= count($notes) ?></span><?php endif; ?></button>
        <div class="dropdown-menu notif-menu">
<?php if (!$notes): ?>
          <div class="notif-empty">You're all caught up.</div>
<?php endif; ?>
<?php foreach ($notes as $n): ?>
          <a class="notif-item" href="<?= h($n['href']) ?>"><span class="ni"><?= icon($n['icon'], 18) ?></span><span><?= h($n['text']) ?></span></a>
<?php endforeach; ?>
        </div>
      </div>
      <div class="dropdown">
        <button class="user-chip" type="button" data-dropdown aria-label="Account menu">
          <?= avatar_html($user['full_name'] ?? 'User') ?>
          <span class="u-text"><strong><?= h($user['full_name'] ?? 'User') ?></strong><small><?= h(ROLE_LABELS[$user['role'] ?? ''] ?? 'User') ?></small></span>
          <?= icon('chevron-down', 16) ?>
        </button>
        <div class="dropdown-menu">
          <a href="settings.php"><?= icon('user', 18) ?> My profile</a>
          <a href="help.php"><?= icon('help', 18) ?> Help &amp; Support</a>
          <hr>
          <form method="post" action="logout.php"><?= csrf_field() ?><button class="item danger" type="submit"><?= icon('log-out', 18) ?> Sign out</button></form>
        </div>
      </div>
    </header>
    <main class="content">
<?php
}

function render_footer(): void {
    $flashes = take_flash();
    $icons = ['success' => 'check-circle', 'error' => 'x-circle', 'warning' => 'alert', 'info' => 'info'];
    ?>
    </main>
    <footer class="foot">© <?= date('Y') ?> GASATTEND · Employee Attendance &amp; Management System</footer>
  </div>
</div>

<div class="toasts" id="toasts" aria-live="polite">
<?php foreach ($flashes as $f): $t = $icons[$f['type']] ?? 'info'; ?>
  <div class="toast <?= h($f['type']) ?>" role="status"><span class="t-icon"><?= icon($t, 22) ?></span><p><?= h($f['message']) ?></p><button type="button" aria-label="Dismiss"><?= icon('x', 16) ?></button></div>
<?php endforeach; ?>
</div>

<div class="modal" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
  <div class="modal-box">
    <h3 id="confirm-title">Are you sure?</h3>
    <p id="confirm-text"></p>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" data-modal-cancel>Cancel</button>
      <button type="button" class="btn btn-danger" data-modal-ok>Confirm</button>
    </div>
  </div>
</div>
</body>
</html>
<?php
}
