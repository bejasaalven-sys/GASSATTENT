<?php
/**
 * Settings
 *   tab=profile (everyone): name, email, change password
 *   tab=users   (administrators): approve/disable accounts, change roles, add users, reset passwords
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
$user = require_login();
$pdo = db();

$isAdmin = can('users.manage');
$tab = input($_GET, 'tab', 'profile') === 'users' && $isAdmin ? 'users' : 'profile';
$errors = [];
$tempNotice = null;
$formValues = [];

function password_problem(string $pw): string {
    if (strlen($pw) < 10) return 'Use at least 10 characters.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Include at least one letter and one number.';
    return '';
}

function active_admin_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input($_POST, 'action');

    /* ---------- My profile ---------- */
    if ($action === 'profile') {
        $tab = 'profile';
        $name = input($_POST, 'full_name');
        $email = strtolower(input($_POST, 'email'));
        $formValues = ['full_name' => $name, 'email' => $email];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $errors['full_name'] = 'Name must be 2 to 120 characters.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) $errors['email'] = 'Enter a valid email address.';
        if (!$errors) {
            $c = $pdo->prepare('SELECT 1 FROM users WHERE (email = ? OR username = ?) AND id <> ?');
            $c->execute([$email, $email, $user['id']]);
            if ($c->fetchColumn()) $errors['email'] = 'That email is already used by another account.';
        }
        if (!$errors) {
            $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?')->execute([$name, $email, $user['id']]);
            flash('Your profile was updated.');
            redirect('settings.php');
        }
    }

    if ($action === 'password') {
        $tab = 'profile';
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $conf = (string)($_POST['confirm'] ?? '');
        $hash = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $hash->execute([$user['id']]);
        if (!password_verify($cur, (string)$hash->fetchColumn())) $errors['current'] = 'Your current password is not correct.';
        if (($p = password_problem($new)) !== '') $errors['new'] = $p;
        if ($conf !== $new) $errors['confirm'] = 'Passwords do not match.';
        if (!$errors) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            flash('Your password was changed.');
            redirect('settings.php');
        }
    }

    /* ---------- Administrator actions ---------- */
    if (in_array($action, ['add_user', 'set_role', 'toggle_active', 'reset_password'], true)) {
        require_can('users.manage');
        $tab = 'users';
        $targetId = (int)($_POST['id'] ?? 0);
        $t = null;
        if ($targetId > 0) {
            $s = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $s->execute([$targetId]);
            $t = $s->fetch();
            if (!$t) { flash('User not found.', 'error'); redirect('settings.php?tab=users'); }
        }

        if ($action === 'add_user') {
            $name = input($_POST, 'full_name');
            $email = strtolower(input($_POST, 'email'));
            $role = input($_POST, 'role', 'secretary');
            $pw = (string)($_POST['password'] ?? '');
            $formValues = ['full_name' => $name, 'email' => $email, 'role' => $role];
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $errors['full_name'] = 'Name must be 2 to 120 characters.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) $errors['email'] = 'Enter a valid email address.';
            if (!isset(ROLE_LABELS[$role])) $errors['role'] = 'Choose a role.';
            if (($p = password_problem($pw)) !== '') $errors['password'] = $p;
            if (!$errors) {
                $c = $pdo->prepare('SELECT 1 FROM users WHERE email = ? OR username = ?');
                $c->execute([$email, $email]);
                if ($c->fetchColumn()) $errors['email'] = 'An account with this email already exists.';
            }
            if (!$errors) {
                $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role, active) VALUES (?,?,?,?,?,1)')
                    ->execute([$email, $email, password_hash($pw, PASSWORD_DEFAULT), $name, $role]);
                flash($name . ' can now sign in as ' . ROLE_LABELS[$role] . '.');
                redirect('settings.php?tab=users');
            }
        } elseif ($t) {
            $isSelf = (int)$t['id'] === (int)$user['id'];
            if ($action === 'set_role') {
                $role = input($_POST, 'role');
                if (!isset(ROLE_LABELS[$role])) { flash('Invalid role.', 'error'); }
                elseif ($isSelf) { flash('You cannot change your own role.', 'error'); }
                elseif ($t['role'] === 'admin' && $role !== 'admin' && (int)$t['active'] === 1 && active_admin_count($pdo) <= 1) { flash('At least one active administrator is required.', 'error'); }
                else {
                    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
                    flash($t['full_name'] . ' is now ' . ROLE_LABELS[$role] . '.');
                }
                redirect('settings.php?tab=users');
            }
            if ($action === 'toggle_active') {
                if ($isSelf) { flash('You cannot disable your own account.', 'error'); }
                elseif ((int)$t['active'] === 1 && $t['role'] === 'admin' && active_admin_count($pdo) <= 1) { flash('At least one active administrator is required.', 'error'); }
                else {
                    $pdo->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$targetId]);
                    flash($t['full_name'] . ((int)$t['active'] === 1 ? ' was disabled.' : ' was approved and can sign in.'), (int)$t['active'] === 1 ? 'warning' : 'success');
                }
                redirect('settings.php?tab=users');
            }
            if ($action === 'reset_password') {
                $temp = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12) . 'a7';
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($temp, PASSWORD_DEFAULT), $targetId]);
                $tempNotice = ['name' => $t['full_name'], 'password' => $temp];       // shown once, never stored
            }
        }
    }
}

$users = $isAdmin ? $pdo->query('SELECT id, username, email, full_name, role, active, last_login_at, created_at FROM users ORDER BY active ASC, full_name')->fetchAll() : [];
$me = $pdo->prepare('SELECT full_name, email, username, role, created_at FROM users WHERE id = ?');
$me->execute([$user['id']]);
$me = $me->fetch();
$pv = $formValues + ['full_name' => $me['full_name'], 'email' => $me['email'] ?: ''];

render_header('Settings', 'settings');

function set_field(string $name, string $label, string $type, string $value, array $errors, string $auto = '', string $form = 's'): void {
    $err = $errors[$name] ?? '';
    ?>
    <div class="field <?= $err ? 'has-error' : '' ?>" data-field>
      <label for="<?= h($form . '-' . $name) ?>"><?= h($label) ?></label>
      <input id="<?= h($form . '-' . $name) ?>" name="<?= h($name) ?>" type="<?= h($type) ?>" value="<?= h($value) ?>" autocomplete="<?= h($auto) ?>" required>
      <div class="field-error"><?= h($err) ?></div>
    </div>
    <?php
}
?>
<div class="page-head">
  <div><h1>Settings</h1><p>Manage your account<?= $isAdmin ? ' and team access' : '' ?>.</p></div>
</div>

<div class="sub-tabs">
  <a class="<?= $tab === 'profile' ? 'active' : '' ?>" href="settings.php">My Profile</a>
<?php if ($isAdmin): ?><a class="<?= $tab === 'users' ? 'active' : '' ?>" href="settings.php?tab=users">Users &amp; Roles</a><?php endif; ?>
</div>

<?php if ($tab === 'profile'): ?>
<div class="two-col" style="grid-template-columns:repeat(2,minmax(0,1fr))">
  <section class="card">
    <div class="card-head"><h2><?= icon('user', 24) ?> Profile</h2></div>
    <div class="cell-user" style="margin-bottom:18px"><?= avatar_html($me['full_name'], null, 'xl') ?><div><strong style="font-size:18px"><?= h($me['full_name']) ?></strong><br><span class="badge role"><?= h(ROLE_LABELS[$me['role']] ?? $me['role']) ?></span><br><small class="muted">Member since <?= h(fmt_date($me['created_at'])) ?></small></div></div>
    <form method="post" class="form-grid" style="grid-template-columns:1fr" novalidate data-validate>
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <?php set_field('full_name', 'Full name', 'text', $pv['full_name'], $errors, 'name', 'p'); ?>
      <?php set_field('email', 'Email address', 'email', $pv['email'], $errors, 'email', 'p'); ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check', 18) ?> Save profile</button></div>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= icon('lock', 24) ?> Change password</h2></div>
    <form method="post" class="form-grid" style="grid-template-columns:1fr" novalidate data-validate>
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <?php set_field('current', 'Current password', 'password', '', $errors, 'current-password', 'w'); ?>
      <?php set_field('new', 'New password', 'password', '', $errors, 'new-password', 'w'); ?>
      <?php set_field('confirm', 'Confirm new password', 'password', '', $errors, 'new-password', 'w'); ?>
      <p class="field-hint">At least 10 characters, with a letter and a number.</p>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check', 18) ?> Update password</button></div>
    </form>
  </section>
</div>

<?php else: ?>
<?php if ($tempNotice): ?>
<div class="banner warning"><?= icon('alert', 18) ?><span>Temporary password for <strong><?= h($tempNotice['name']) ?></strong>: <code style="font-size:15px;user-select:all"><?= h($tempNotice['password']) ?></code> — copy it now. It will not be shown again. Ask them to change it after signing in.</span></div>
<?php endif; ?>
<div class="two-col" style="grid-template-columns:minmax(0,1fr) 360px">
  <section class="card">
    <div class="card-head"><h2><?= icon('users', 24) ?> Accounts</h2><span class="muted"><?= count($users) ?> user(s)</span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last sign-in</th><th class="num">Action</th></tr></thead>
        <tbody>
<?php foreach ($users as $u): $self = (int)$u['id'] === (int)$user['id']; $pending = (int)$u['active'] === 0 && $u['last_login_at'] === null; ?>
          <tr>
            <td><div class="cell-user"><?= avatar_html($u['full_name'], null, 'sm') ?><div><strong><?= h($u['full_name']) ?><?= $self ? ' (you)' : '' ?></strong><small><?= h($u['email'] ?: $u['username']) ?></small></div></div></td>
            <td>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <select name="role" data-autosubmit <?= $self ? 'disabled' : '' ?> aria-label="Role for <?= h($u['full_name']) ?>" style="padding:7px 10px;border:1px solid #d5ddee;border-radius:10px">
<?php foreach (ROLE_LABELS as $rk => $rl): ?>
                  <option value="<?= h($rk) ?>" <?= $u['role'] === $rk ? 'selected' : '' ?>><?= h($rl) ?></option>
<?php endforeach; ?>
                </select></form>
            </td>
            <td><span class="badge <?= (int)$u['active'] === 1 ? 'active' : ($pending ? 'late' : 'inactive') ?>"><?= (int)$u['active'] === 1 ? 'Active' : ($pending ? 'Pending' : 'Disabled') ?></span></td>
            <td><?= $u['last_login_at'] ? h(fmt_date($u['last_login_at'], 'M j, g:i A')) : '—' ?></td>
            <td class="num"><div class="dropdown row-actions">
              <button class="icon-btn" type="button" data-dropdown aria-label="Actions for <?= h($u['full_name']) ?>"><?= icon('more', 20) ?></button>
              <div class="dropdown-menu">
<?php if (!$self): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="item <?= (int)$u['active'] === 1 ? 'danger' : '' ?>" type="submit"><?= icon((int)$u['active'] === 1 ? 'x-circle' : 'check-circle', 18) ?> <?= (int)$u['active'] === 1 ? 'Disable account' : ($pending ? 'Approve account' : 'Enable account') ?></button></form>
<?php endif; ?>
                <form method="post" data-confirm="<?= h('Generate a new temporary password for ' . $u['full_name'] . '? Their current password will stop working.') ?>" data-confirm-title="Reset password" data-confirm-btn="Reset" data-confirm-style="primary">
                  <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="item" type="submit"><?= icon('lock', 18) ?> Reset password</button></form>
              </div>
            </div></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted small" style="margin-top:12px"><strong>Roles:</strong> Administrator — everything, including users. Manager — employees, attendance and reports. Secretary — view employees, log attendance, view reports.</p>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= icon('user-plus', 24) ?> Add user</h2></div>
    <form method="post" class="form-grid" style="grid-template-columns:1fr" novalidate data-validate>
      <?= csrf_field() ?><input type="hidden" name="action" value="add_user">
      <?php set_field('full_name', 'Full name', 'text', $formValues['full_name'] ?? '', $errors, 'off', 'n'); ?>
      <?php set_field('email', 'Email address', 'email', $formValues['email'] ?? '', $errors, 'off', 'n'); ?>
      <div class="field" data-field><label for="n-role">Role</label>
        <select id="n-role" name="role">
<?php foreach (ROLE_LABELS as $rk => $rl): ?>
          <option value="<?= h($rk) ?>" <?= ($formValues['role'] ?? 'secretary') === $rk ? 'selected' : '' ?>><?= h($rl) ?></option>
<?php endforeach; ?>
        </select></div>
      <?php set_field('password', 'Temporary password', 'password', '', $errors, 'new-password', 'n'); ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('plus', 18) ?> Create user</button></div>
    </form>
  </section>
</div>
<?php endif; ?>
<?php render_footer(); ?>
