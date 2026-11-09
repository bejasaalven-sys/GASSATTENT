<?php
/** Add a new employee, or edit one (employee_form.php?id=123). */
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
$user = require_login();
require_can('employees.write');
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$emp = null;
if ($id > 0) {
    $s = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $s->execute([$id]);
    $emp = $s->fetch();
    if (!$emp) { flash('That employee could not be found.', 'error'); redirect('employees.php'); }
}
$editing = $emp !== null;

/* Form values: saved record -> defaults for a new employee */
$v = $emp ?: ['employee_no' => next_employee_no(), 'full_name' => '', 'email' => '', 'phone' => '', 'position' => '', 'department' => '',
              'hire_date' => date('Y-m-d'), 'employment_type' => 'Full-time', 'active' => 1, 'photo' => null];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $v = array_merge($v, [
        'employee_no' => input($_POST, 'employee_no'),
        'full_name' => input($_POST, 'full_name'),
        'email' => strtolower(input($_POST, 'email')),
        'phone' => input($_POST, 'phone'),
        'position' => input($_POST, 'position'),
        'department' => input($_POST, 'department'),
        'hire_date' => input($_POST, 'hire_date'),
        'employment_type' => input($_POST, 'employment_type', 'Full-time'),
        'active' => input($_POST, 'active', '1') === '0' ? 0 : 1,
    ]);

    /* ---- Server-side validation ---- */
    if ($v['employee_no'] === '') $errors['employee_no'] = 'Employee number is required.';
    elseif (!preg_match('/^[A-Za-z0-9\-_\/]{1,30}$/', $v['employee_no'])) $errors['employee_no'] = 'Use letters, numbers, dashes or slashes only (max 30).';
    if ($v['full_name'] === '') $errors['full_name'] = 'Full name is required.';
    elseif (mb_strlen($v['full_name']) < 2 || mb_strlen($v['full_name']) > 120) $errors['full_name'] = 'Name must be 2 to 120 characters.';
    if ($v['email'] !== '' && (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 120)) $errors['email'] = 'Enter a valid email address.';
    if ($v['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{7,30}$/', $v['phone'])) $errors['phone'] = 'Enter a valid phone number.';
    if (mb_strlen($v['position']) > 80) $errors['position'] = 'Position is too long (80 max).';
    if (mb_strlen($v['department']) > 80) $errors['department'] = 'Department is too long (80 max).';
    if ($v['hire_date'] !== '') {
        if (!valid_date($v['hire_date'])) $errors['hire_date'] = 'Enter a valid date.';
        elseif ($v['hire_date'] > date('Y-m-d', strtotime('+1 year'))) $errors['hire_date'] = 'Hire date is too far in the future.';
    }
    if (!in_array($v['employment_type'], EMPLOYMENT_TYPES, true)) $errors['employment_type'] = 'Choose an employment type.';

    /* ---- Uniqueness ---- */
    if (!isset($errors['employee_no'])) {
        $c = $pdo->prepare('SELECT 1 FROM employees WHERE employee_no = ? AND id <> ?');
        $c->execute([$v['employee_no'], $id]);
        if ($c->fetchColumn()) $errors['employee_no'] = 'That employee number is already in use.';
    }
    if ($v['email'] !== '' && !isset($errors['email'])) {
        $c = $pdo->prepare('SELECT 1 FROM employees WHERE email = ? AND id <> ?');
        $c->execute([$v['email'], $id]);
        if ($c->fetchColumn()) $errors['email'] = 'Another employee already uses this email.';
    }

    /* ---- Photo (only handled once everything else is valid) ---- */
    $newPhoto = null;
    if (!$errors) {
        try {
            $newPhoto = save_employee_photo($_FILES['photo'] ?? []);
        } catch (RuntimeException $e) {
            $errors['photo'] = $e->getMessage();
        }
    }

    if (!$errors) {
        $photo = $emp['photo'] ?? null;
        if ($newPhoto !== null) { delete_employee_photo($photo); $photo = $newPhoto; }
        elseif (isset($_POST['remove_photo'])) { delete_employee_photo($photo); $photo = null; }

        $params = [$v['employee_no'], $v['full_name'], $v['email'] !== '' ? $v['email'] : null, $v['phone'], $v['position'], $v['department'],
                   $v['hire_date'] !== '' ? $v['hire_date'] : null, $v['employment_type'], $photo, (int)$v['active']];
        try {
            if ($editing) {
                $sql = 'UPDATE employees SET employee_no=?, full_name=?, email=?, phone=?, position=?, department=?, hire_date=?, employment_type=?, photo=?, active=? WHERE id=?';
                $params[] = $id;
                flash($v['full_name'] . ' was updated.');
            } else {
                $sql = 'INSERT INTO employees (employee_no, full_name, email, phone, position, department, hire_date, employment_type, photo, active) VALUES (?,?,?,?,?,?,?,?,?,?)';
                flash($v['full_name'] . ' was added to your team.');
            }
            $pdo->prepare($sql)->execute($params);
            redirect('employees.php');
        } catch (PDOException $e) {
            take_flash();                                   // discard the optimistic success message
            if ($newPhoto !== null) delete_employee_photo($newPhoto);
            $errors['form'] = (string)$e->getCode() === '23000' ? 'That employee number or email is already in use.' : 'Could not save the employee. Please try again.';
        }
    }
}

$title = $editing ? 'Edit Employee' : 'Add New Employee';
render_header($title, 'employees');

/** One labelled field with inline error text. */
function emp_field(string $name, string $label, string $type, array $v, array $errors, array $attrs = [], bool $required = false): void {
    $err = $errors[$name] ?? '';
    $extra = '';
    foreach ($attrs as $k => $val) { $extra .= ' ' . h($k) . '="' . h($val) . '"'; }
    ?>
    <div class="field <?= $err ? 'has-error' : '' ?>" data-field>
      <label for="f-<?= h($name) ?>"><?= h($label) ?><?php if ($required): ?> <span class="req">*</span><?php endif; ?></label>
      <input id="f-<?= h($name) ?>" name="<?= h($name) ?>" type="<?= h($type) ?>" value="<?= h($v[$name] ?? '') ?>"<?= $required ? ' required' : '' ?><?= $extra ?>>
      <div class="field-error"><?= h($err) ?></div>
    </div>
    <?php
}
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="employees.php">Employees</a> <?= icon('chevron-right', 14) ?> <span><?= h($title) ?></span></div>
    <h1><?= h($title) ?></h1>
    <p><?= $editing ? 'Update this team member’s information.' : 'Register a new team member and add their information to your organization.' ?></p>
  </div>
  <div class="head-actions"><a class="btn btn-ghost" href="employees.php"><?= icon('arrow-left', 18) ?> Back to Employees</a></div>
</div>

<?php if (isset($errors['form'])): ?><div class="banner error"><?= icon('alert', 18) ?><span><?= h($errors['form']) ?></span></div><?php endif; ?>
<?php if ($errors && !isset($errors['form'])): ?><div class="banner error"><?= icon('alert', 18) ?><span>Please fix the highlighted fields and try again.</span></div><?php endif; ?>

<form class="two-col" method="post" enctype="multipart/form-data" novalidate data-validate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <section class="card photo-card">
    <h2>Profile Photo</h2>
    <span id="photo-preview"><?= avatar_html((string)($v['full_name'] ?: 'New Employee'), $v['photo'] ?? null, 'xl') ?></span>
    <div class="field" data-field style="width:100%;align-items:center">
      <input class="sr-only" id="f-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-photo-input>
      <label class="btn btn-ghost upload-btn" for="f-photo"><?= icon('camera', 18) ?> Upload photo</label>
      <div class="field-hint">JPG, PNG or WebP · max 2 MB</div>
      <div class="field-error"><?= h($errors['photo'] ?? '') ?></div>
    </div>
<?php if ($editing && $v['photo']): ?>
    <label class="small"><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label>
<?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= icon('briefcase', 24) ?> Employee Information</h2></div>
    <div class="form-grid">
      <?php emp_field('employee_no', 'Employee No.', 'text', $v, $errors, ['maxlength' => '30', 'placeholder' => 'e.g. GS-013'], true); ?>
      <?php emp_field('full_name', 'Full Name', 'text', $v, $errors, ['maxlength' => '120', 'minlength' => '2', 'autocomplete' => 'off', 'placeholder' => 'e.g. Maria Santos'], true); ?>
      <?php emp_field('email', 'Email Address', 'email', $v, $errors, ['maxlength' => '120', 'placeholder' => 'name@example.com']); ?>
      <?php emp_field('phone', 'Phone Number', 'tel', $v, $errors, ['maxlength' => '30', 'pattern' => '[0-9+()\-\s]{7,30}', 'placeholder' => '0917 000 0000', 'data-msg-pattern' => 'Enter a valid phone number.']); ?>
      <?php emp_field('position', 'Position / Job Title', 'text', $v, $errors, ['maxlength' => '80', 'placeholder' => 'e.g. Cashier']); ?>
      <?php emp_field('department', 'Department', 'text', $v, $errors, ['maxlength' => '80', 'list' => 'dept-list', 'placeholder' => 'e.g. Operations']); ?>
      <datalist id="dept-list">
<?php foreach ($pdo->query("SELECT DISTINCT department FROM employees WHERE department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN) as $d): ?>
        <option value="<?= h($d) ?>">
<?php endforeach; ?>
      </datalist>
      <?php emp_field('hire_date', 'Date Hired', 'date', $v, $errors, ['max' => date('Y-m-d', strtotime('+1 year'))]); ?>
      <div class="field <?= isset($errors['employment_type']) ? 'has-error' : '' ?>" data-field>
        <label for="f-employment_type">Employment Type</label>
        <select id="f-employment_type" name="employment_type">
<?php foreach (EMPLOYMENT_TYPES as $t): ?>
          <option <?= $v['employment_type'] === $t ? 'selected' : '' ?>><?= h($t) ?></option>
<?php endforeach; ?>
        </select>
        <div class="field-error"><?= h($errors['employment_type'] ?? '') ?></div>
      </div>
      <div class="field" data-field>
        <label for="f-active">Status</label>
        <select id="f-active" name="active">
          <option value="1" <?= (int)$v['active'] === 1 ? 'selected' : '' ?>>Active</option>
          <option value="0" <?= (int)$v['active'] === 0 ? 'selected' : '' ?>>Inactive</option>
        </select>
        <div class="field-hint">Inactive employees are hidden from attendance entry.</div>
      </div>
    </div>
    <div class="form-actions">
      <a class="btn btn-ghost" href="employees.php">Cancel</a>
      <button class="btn btn-primary" type="submit"><?= icon('check', 18) ?> <?= $editing ? 'Save Changes' : 'Add Employee' ?></button>
    </div>
  </section>
</form>
<?php render_footer(); ?>
