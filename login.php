<?php
/**
 * Sign In / Create Account (one page, two tabs).
 *  - The very first account created becomes the Administrator.
 *  - Later sign-ups are created as "Secretary" and must be approved by an
 *    administrator (Settings > Users) before they can sign in.
 */
declare(strict_types=1);
require __DIR__ . '/config.php';

try {
    if (current_user()) redirect('index.php');
} catch (Throwable $e) { /* handled below */ }

$errors = [];          // field name => message ('form' = banner message)
$values = [];          // re-fill fields after a failed submit
$tab = input($_GET, 'tab') === 'register' ? 'register' : 'login';
$firstRun = false;
$bannerInfo = '';

try {
    $firstRun = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
} catch (Throwable $e) {
    $errors['form'] = 'Cannot connect to the database. Import database.sql in phpMyAdmin and check config.php.';
}
if ($firstRun && $_SERVER['REQUEST_METHOD'] !== 'POST') $tab = 'register';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($errors['form'])) {
    verify_csrf();
    $action = input($_POST, 'action');

    /* ---------------- SIGN IN ---------------- */
    if ($action === 'login') {
        $tab = 'login';
        $identity = input($_POST, 'identity');
        $password = (string)($_POST['password'] ?? '');
        $values['identity'] = $identity;

        if (login_locked_seconds() > 0) {
            $errors['form'] = 'Too many failed attempts. Please wait ' . login_locked_seconds() . ' seconds and try again.';
        } elseif ($identity === '' || $password === '') {
            if ($identity === '') $errors['identity'] = 'Enter your email or username.';
            if ($password === '') $errors['password'] = 'Enter your password.';
        } else {
            try {
                $s = db()->prepare('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1');
                $s->execute([$identity, $identity]);
                $u = $s->fetch();
                if ($u && password_verify($password, $u['password_hash'])) {
                    if ((int)$u['active'] !== 1) {
                        $errors['form'] = 'Your account is not active yet. Please wait for an administrator to approve it.';
                    } else {
                        session_regenerate_id(true);
                        login_succeeded();
                        $_SESSION['user_id'] = (int)$u['id'];
                        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$u['id']]);
                        flash('Welcome back, ' . $u['full_name'] . '!');
                        redirect('index.php');
                    }
                } else {
                    login_failed();
                    $errors['form'] = 'Incorrect email/username or password.';
                }
            } catch (Throwable $e) {
                $errors['form'] = strpos($e->getMessage(), 'Unknown column') !== false
                    ? 'Your database is missing new columns. Import database.sql in phpMyAdmin, then try again.'
                    : 'Something went wrong while signing in. Please try again.';
            }
        }
    }

    /* ---------------- CREATE ACCOUNT ---------------- */
    if ($action === 'register') {
        $tab = 'register';
        $name = input($_POST, 'full_name');
        $email = strtolower(input($_POST, 'email'));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        $values = ['full_name' => $name, 'email' => $email];

        if ($name === '' || mb_strlen($name) < 2) $errors['full_name'] = 'Enter your full name.';
        elseif (mb_strlen($name) > 120) $errors['full_name'] = 'Name is too long (120 characters max).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) $errors['email'] = 'Enter a valid email address.';
        if (strlen($password) < 10) $errors['password'] = 'Use at least 10 characters.';
        elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) $errors['password'] = 'Include at least one letter and one number.';
        if ($confirm !== $password) $errors['confirm'] = 'Passwords do not match.';

        if (!$errors) {
            try {
                $pdo = db();
                $exists = $pdo->prepare('SELECT 1 FROM users WHERE email = ? OR username = ?');
                $exists->execute([$email, $email]);
                if ($exists->fetchColumn()) {
                    $errors['email'] = 'An account with this email already exists.';
                } else {
                    $isFirst = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
                    $ins = $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role, active) VALUES (?, ?, ?, ?, ?, ?)');
                    $ins->execute([$email, $email, password_hash($password, PASSWORD_DEFAULT), $name, $isFirst ? 'admin' : 'secretary', $isFirst ? 1 : 0]);
                    if ($isFirst) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
                        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$pdo->lastInsertId()]);
                        flash('Administrator account created. Welcome to gasattend!');
                        redirect('index.php');
                    }
                    flash('Account created. An administrator must approve it before you can sign in.', 'info');
                    redirect('login.php');
                }
            } catch (Throwable $e) {
                $errors['form'] = 'Could not create the account. Please try again.';
            }
        }
    }
}

$flashes = take_flash();

/** Renders one auth input with icon, optional show/hide toggle and error text. */
function auth_field(string $form, string $name, string $type, string $ico, string $placeholder, array $errors, string $value = '', string $autocomplete = '', bool $toggle = false): void {
    $id = $form . '-' . $name;
    $err = $errors[$name] ?? '';
    ?>
    <div class="auth-field <?= $err ? 'has-error' : '' ?>" data-field>
      <div class="input-wrap">
        <span class="lead-ico"><?= icon($ico, 20) ?></span>
        <input id="<?= h($id) ?>" name="<?= h($name) ?>" type="<?= h($type) ?>" placeholder="<?= h($placeholder) ?>" aria-label="<?= h($placeholder) ?>" value="<?= h($value) ?>" autocomplete="<?= h($autocomplete) ?>" required<?= ($name === 'password' && $form === 'register') ? ' minlength="10"' : '' ?>>
        <?php if ($toggle): ?><button class="toggle-pw" type="button" aria-label="Show or hide password"><span class="on"><?= icon('eye-off', 20) ?></span><span class="off"><?= icon('eye', 20) ?></span></button><?php endif; ?>
      </div>
      <?php if ($name === 'password' && $form === 'register'): ?><div class="strength" id="strength"><i></i></div><?php endif; ?>
      <div class="field-error"><?= h($err) ?></div>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in | GASATTEND</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="style.css">
<script defer src="app.js"></script>
</head>
<body class="auth-body">
<main class="auth-shell">

  <!-- Left: branding -->
  <section class="auth-art">
    <div class="auth-brand">
      <img src="logo.svg" alt="">
      <div><strong>gasattend</strong><small>Employee Attendance &amp;<br>Management System</small></div>
    </div>
    <h1>Smarter Attendance.<br>A More <em>Productive</em> Team.</h1>
    <p class="lead">Track attendance, manage your team,<br>and boost productivity — all in one powerful<br>and easy-to-use platform.</p>
    <ul class="auth-features">
      <li><span class="dot"><?= icon('calendar', 22) ?></span>Easy Attendance Tracking</li>
      <li><span class="dot"><?= icon('users', 22) ?></span>Efficient Employee Management</li>
      <li><span class="dot"><?= icon('bar-chart', 22) ?></span>Real-Time Reports</li>
    </ul>
    <div class="auth-scene" aria-hidden="true">
      <svg viewBox="0 0 800 300" preserveAspectRatio="xMinYMax slice">
        <defs><linearGradient id="desk" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#8aa6e6"/><stop offset="1" stop-color="#b9c9f0"/></linearGradient></defs>
        <path d="M0 240 L520 215 L800 250 L800 300 L0 300Z" fill="url(#desk)" opacity=".85"/>
        <path d="M70 232 L110 120 Q114 108 126 110 L330 140 Q342 142 340 154 L318 238Z" fill="#c9d6f3"/>
        <path d="M120 128 L322 156 L306 226 L96 212Z" fill="#12306f"/>
        <path d="M40 238 L340 248 L330 262 L52 252Z" fill="#9fb2dd"/>
        <rect x="470" y="176" width="64" height="64" rx="8" fill="#f4f7ff"/><path d="M534 190q22 4 18 30-3 14-18 14" fill="none" stroke="#f4f7ff" stroke-width="9"/>
        <circle cx="502" cy="208" r="13" fill="none" stroke="#19d3a0" stroke-width="5"/><path d="M496 208l5 5 9-10" fill="none" stroke="#19d3a0" stroke-width="4" stroke-linecap="round"/>
        <rect x="560" y="170" width="86" height="76" rx="6" fill="#f4f7ff"/>
        <g fill="#1a8f68"><path d="M603 170C585 130 560 118 534 112c20 16 30 34 36 58z"/><path d="M603 170C610 120 636 96 668 92c-22 16-34 44-40 78z"/><path d="M603 170C600 118 596 82 603 44c10 38 8 80 6 126z"/><path d="M603 170C640 150 672 150 700 160c-30 4-58 10-92 20z"/></g>
      </svg>
    </div>
  </section>

  <!-- Right: forms -->
  <section class="auth-panel">
    <div class="lang-pill"><?= icon('globe', 18) ?> English <?= icon('chevron-down', 16) ?></div>
    <div class="auth-box">
      <div class="auth-logo"><img src="logo.svg" alt=""><span>gasattend</span></div>
      <h2>Welcome to gasattend</h2>
      <p class="sub" id="auth-sub">Sign in to continue to your workspace</p>

      <div class="tabs" role="tablist">
        <button type="button" class="tab <?= $tab === 'login' ? 'active' : '' ?>" data-tab="login" role="tab"><?= icon('log-in', 20) ?> Sign In</button>
        <button type="button" class="tab <?= $tab === 'register' ? 'active' : '' ?>" data-tab="register" role="tab"><?= icon('user-plus', 20) ?> Create Account</button>
      </div>

      <?php foreach ($flashes as $f): ?>
        <div class="banner <?= h($f['type'] === 'error' ? 'error' : ($f['type'] === 'success' ? 'success' : 'info')) ?>"><?= icon('info', 18) ?><span><?= h($f['message']) ?></span></div>
      <?php endforeach; ?>

      <!-- SIGN IN -->
      <form class="auth-form <?= $tab === 'login' ? 'active' : '' ?>" data-panel="login" method="post" action="login.php" novalidate data-validate>
        <?= csrf_field() ?><input type="hidden" name="action" value="login">
        <?php if ($tab === 'login' && isset($errors['form'])): ?><div class="banner error"><?= icon('alert', 18) ?><span><?= h($errors['form']) ?></span></div><?php endif; ?>
        <?php auth_field('login', 'identity', 'text', 'mail', 'Email or Username', $errors, $values['identity'] ?? '', 'username'); ?>
        <?php auth_field('login', 'password', 'password', 'lock', 'Password', $errors, '', 'current-password', true); ?>
        <button class="btn btn-primary btn-auth" type="submit">Sign In <?= icon('arrow-right', 20) ?></button>
      </form>

      <!-- CREATE ACCOUNT -->
      <form class="auth-form <?= $tab === 'register' ? 'active' : '' ?>" data-panel="register" method="post" action="login.php" novalidate data-validate>
        <?= csrf_field() ?><input type="hidden" name="action" value="register">
        <?php if ($firstRun): ?><div class="auth-note"><?= icon('shield', 18) ?><span>No accounts exist yet. The first account you create becomes the <strong>Administrator</strong>.</span></div>
        <?php else: ?><div class="auth-note"><?= icon('info', 18) ?><span>New accounts are reviewed by an administrator before they can sign in.</span></div><?php endif; ?>
        <?php if ($tab === 'register' && isset($errors['form'])): ?><div class="banner error"><?= icon('alert', 18) ?><span><?= h($errors['form']) ?></span></div><?php endif; ?>
        <?php auth_field('register', 'full_name', 'text', 'user', 'Full Name', $errors, $values['full_name'] ?? '', 'name'); ?>
        <?php auth_field('register', 'email', 'email', 'mail', 'Email Address', $errors, $values['email'] ?? '', 'email'); ?>
        <?php auth_field('register', 'password', 'password', 'lock', 'Password', $errors, '', 'new-password', true); ?>
        <?php auth_field('register', 'confirm', 'password', 'lock', 'Confirm Password', $errors, '', 'new-password', true); ?>
        <button class="btn btn-primary btn-auth" type="submit">Create Account <?= icon('arrow-right', 20) ?></button>
      </form>

      <div class="divider"><span>or continue with</span></div>
      <div class="sso">
        <button type="button" disabled title="Single sign-on is not configured in this prototype">
          <svg width="22" height="22" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
          <span data-sso-label>Sign up with Google</span>
        </button>
        <button type="button" disabled title="Single sign-on is not configured in this prototype">
          <svg width="22" height="22" viewBox="0 0 23 23" aria-hidden="true"><path fill="#f25022" d="M1 1h10v10H1z"/><path fill="#7fba00" d="M12 1h10v10H12z"/><path fill="#00a4ef" d="M1 12h10v10H1z"/><path fill="#ffb900" d="M12 12h10v10H12z"/></svg>
          <span data-sso-label>Sign up with Microsoft</span>
        </button>
      </div>

      <p class="auth-switch" id="auth-switch"></p>
    </div>
  </section>
</main>
</body>
</html>
