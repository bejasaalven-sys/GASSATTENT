<?php
/**
 * Attendance: log, edit and delete one employee's record for a date.
 * One record per employee per day (logging again updates that record).
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
$user = require_login();
$pdo = db();
$today = date('Y-m-d');

$date = input($_GET, 'date', $today);
if (!valid_date($date)) $date = $today;
$editId = (int)($_GET['edit'] ?? 0);
$errors = [];
$f = ['employee_id' => (int)($_GET['employee_id'] ?? 0), 'status' => 'Present', 'time_in' => '', 'time_out' => '', 'notes' => ''];

/* ---------- POST: delete / save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input($_POST, 'action');

    if ($action === 'delete') {
        require_can('attendance.delete');
        $rid = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM attendance WHERE id = ?')->execute([$rid]);
        flash('Attendance record deleted.', 'warning');
        redirect('attendance.php?date=' . urlencode(valid_date(input($_POST, 'date')) ? input($_POST, 'date') : $today));
    }

    if ($action === 'save') {
        require_can('attendance.write');
        $recordId = (int)($_POST['record_id'] ?? 0);
        $date = input($_POST, 'attendance_date', $date);
        $f = ['employee_id' => (int)($_POST['employee_id'] ?? 0), 'status' => input($_POST, 'status', 'Present'),
              'time_in' => input($_POST, 'time_in'), 'time_out' => input($_POST, 'time_out'), 'notes' => input($_POST, 'notes')];
        $editId = $recordId;

        if ($recordId > 0) {                                  // editing: employee + date are fixed
            $s = $pdo->prepare('SELECT employee_id, attendance_date FROM attendance WHERE id = ?');
            $s->execute([$recordId]);
            $orig = $s->fetch();
            if (!$orig) { flash('That record no longer exists.', 'error'); redirect('attendance.php?date=' . urlencode($date)); }
            $f['employee_id'] = (int)$orig['employee_id'];
            $date = $orig['attendance_date'];
        }

        if (!valid_date($date)) $errors['attendance_date'] = 'Choose a valid date.';
        if ($f['employee_id'] < 1) {
            $errors['employee_id'] = 'Select an employee.';
        } else {
            $s = $pdo->prepare('SELECT active, archived_at FROM employees WHERE id = ?');
            $s->execute([$f['employee_id']]);
            $e = $s->fetch();
            if (!$e) $errors['employee_id'] = 'Employee not found.';
            elseif ($recordId === 0 && ((int)$e['active'] !== 1 || $e['archived_at'] !== null)) $errors['employee_id'] = 'That employee is inactive or archived.';
        }
        if (!in_array($f['status'], STATUSES, true)) $errors['status'] = 'Choose a valid status.';

        $needsTime = in_array($f['status'], ['Present', 'Late'], true);
        if (!isset($errors['status']) && !$needsTime) { $f['time_in'] = ''; $f['time_out'] = ''; }   // times only apply to Present/Late
        if ($needsTime && $f['time_in'] === '') $errors['time_in'] = 'Time in is required for ' . $f['status'] . '.';
        if ($f['time_in'] !== '' && !valid_time($f['time_in'])) $errors['time_in'] = 'Use the HH:MM format.';
        if ($f['time_out'] !== '' && !valid_time($f['time_out'])) $errors['time_out'] = 'Use the HH:MM format.';
        if (!isset($errors['time_in']) && !isset($errors['time_out']) && $f['time_in'] !== '' && $f['time_out'] !== '' && $f['time_out'] < $f['time_in']) {
            $errors['time_out'] = 'Time out is earlier than time in.';
        }
        if (!isset($errors['attendance_date']) && !isset($errors['status']) && $date > $today && in_array($f['status'], ['Present', 'Late', 'Absent'], true)) {
            $errors['attendance_date'] = 'Future dates can only be Leave or Rest Day.';
        }
        if (mb_strlen($f['notes']) > 500) $errors['notes'] = 'Notes must be 500 characters or fewer.';

        if (!$errors) {
            try {
                $tin = $f['time_in'] !== '' ? $f['time_in'] : null;
                $tout = $f['time_out'] !== '' ? $f['time_out'] : null;
                if ($recordId > 0) {
                    $pdo->prepare('UPDATE attendance SET status=?, time_in=?, time_out=?, notes=?, created_by=? WHERE id=?')
                        ->execute([$f['status'], $tin, $tout, $f['notes'], $user['id'], $recordId]);
                } else {
                    $pdo->prepare('INSERT INTO attendance (employee_id, attendance_date, status, time_in, time_out, notes, created_by) VALUES (?,?,?,?,?,?,?)
                                   ON DUPLICATE KEY UPDATE status=VALUES(status), time_in=VALUES(time_in), time_out=VALUES(time_out), notes=VALUES(notes), created_by=VALUES(created_by)')
                        ->execute([$f['employee_id'], $date, $f['status'], $tin, $tout, $f['notes'], $user['id']]);
                }
                flash('Attendance saved.');
                redirect('attendance.php?date=' . urlencode($date));
            } catch (PDOException $e) {
                $errors['form'] = 'Could not save the record. Please try again.';
            }
        }
    }
}

/* ---------- Edit mode: load the record into the form ---------- */
$editing = null;
if ($editId > 0) {
    $s = $pdo->prepare('SELECT a.*, e.full_name, e.employee_no FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.id = ?');
    $s->execute([$editId]);
    $editing = $s->fetch() ?: null;
    if ($editing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $date = $editing['attendance_date'];
        $f = ['employee_id' => (int)$editing['employee_id'], 'status' => $editing['status'], 'time_in' => substr((string)$editing['time_in'], 0, 5),
              'time_out' => substr((string)$editing['time_out'], 0, 5), 'notes' => $editing['notes']];
    }
}

/* ---------- Data for the page ---------- */
$employees = $pdo->query('SELECT id, employee_no, full_name FROM employees WHERE active = 1 AND archived_at IS NULL ORDER BY full_name')->fetchAll();
$s = $pdo->prepare('SELECT a.*, e.full_name, e.employee_no, e.photo FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.attendance_date = ? ORDER BY e.full_name');
$s->execute([$date]);
$records = $s->fetchAll();
$logged = array_column($records, 'employee_id');
$counts = array_fill_keys(STATUSES, 0);
foreach ($records as $r) { $counts[$r['status']]++; }
$notLogged = 0;
foreach ($employees as $e) { if (!in_array($e['id'], $logged, false)) $notLogged++; }
$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));

render_header('Attendance', 'attendance');

function att_err(array $errors, string $k): string {
    return '<div class="field-error">' . h($errors[$k] ?? '') . '</div>';
}
?>
<div class="page-head">
  <div>
    <h1>Attendance</h1>
    <p>Log and review daily attendance for your team.</p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="attendance_bulk.php?date=<?= h($date) ?>"><?= icon('users', 18) ?> Bulk Entry</a>
  </div>
</div>

<?php if (isset($errors['form'])): ?><div class="banner error"><?= icon('alert', 18) ?><span><?= h($errors['form']) ?></span></div><?php endif; ?>

<div class="two-col">
  <!-- Log / edit form -->
  <section class="card">
    <div class="card-head"><h2><?= icon($editing ? 'edit' : 'calendar-check', 24) ?> <?= $editing ? 'Edit Record' : 'Log Attendance' ?></h2></div>
<?php if (can('attendance.write')): ?>
    <form method="post" class="form-grid" style="grid-template-columns:1fr" novalidate data-validate data-attendance-form data-work-start="<?= h(WORK_START) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="record_id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

      <div class="field <?= isset($errors['employee_id']) ? 'has-error' : '' ?>" data-field>
        <label for="a-employee">Employee <span class="req">*</span></label>
<?php if ($editing): ?>
        <input id="a-employee" value="<?= h($editing['employee_no'] . ' — ' . $editing['full_name']) ?>" readonly>
<?php else: ?>
        <select id="a-employee" name="employee_id" required data-msg-required="Select an employee.">
          <option value="">Select employee…</option>
<?php foreach ($employees as $e): ?>
          <option value="<?= (int)$e['id'] ?>" <?= $f['employee_id'] === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['employee_no'] . ' — ' . $e['full_name']) ?><?= in_array($e['id'], $logged, false) ? '  (logged)' : '' ?></option>
<?php endforeach; ?>
        </select>
<?php endif; ?>
        <?= att_err($errors, 'employee_id') ?>
      </div>

      <div class="field <?= isset($errors['attendance_date']) ? 'has-error' : '' ?>" data-field>
        <label for="a-date">Date <span class="req">*</span></label>
        <input id="a-date" type="date" name="attendance_date" value="<?= h($date) ?>" required <?= $editing ? 'readonly' : '' ?>>
        <?= att_err($errors, 'attendance_date') ?>
      </div>

      <div class="field <?= isset($errors['status']) ? 'has-error' : '' ?>" data-field>
        <label for="a-status">Status <span class="req">*</span></label>
        <select id="a-status" name="status" data-status-select>
<?php foreach (STATUSES as $st): ?>
          <option <?= $f['status'] === $st ? 'selected' : '' ?>><?= h($st) ?></option>
<?php endforeach; ?>
        </select>
        <?= att_err($errors, 'status') ?>
      </div>

      <div class="form-grid">
        <div class="field <?= isset($errors['time_in']) ? 'has-error' : '' ?>" data-field>
          <label for="a-in">Time in</label>
          <input id="a-in" type="time" name="time_in" value="<?= h($f['time_in']) ?>" data-time-in>
          <?= att_err($errors, 'time_in') ?>
        </div>
        <div class="field <?= isset($errors['time_out']) ? 'has-error' : '' ?>" data-field>
          <label for="a-out">Time out</label>
          <input id="a-out" type="time" name="time_out" value="<?= h($f['time_out']) ?>" data-time-out>
          <?= att_err($errors, 'time_out') ?>
        </div>
      </div>
      <div class="field-hint" data-time-hint style="margin-top:-8px"></div>

      <div class="field <?= isset($errors['notes']) ? 'has-error' : '' ?>" data-field>
        <label for="a-notes">Notes (optional)</label>
        <textarea id="a-notes" name="notes" maxlength="500" rows="3" placeholder="Short note about this record"><?= h($f['notes']) ?></textarea>
        <?= att_err($errors, 'notes') ?>
      </div>

      <div class="form-actions" style="justify-content:stretch">
        <button class="btn btn-primary" style="flex:1" type="submit" <?= (!$employees && !$editing) ? 'disabled' : '' ?>><?= icon('check', 18) ?> <?= $editing ? 'Update Record' : 'Save Attendance' ?></button>
<?php if ($editing): ?><a class="btn btn-ghost" href="attendance.php?date=<?= h($date) ?>">Cancel</a><?php endif; ?>
      </div>
      <p class="muted small">Saving again for the same employee and date updates that day's record.</p>
<?php if (!$employees && !$editing): ?><div class="banner warning"><?= icon('alert', 18) ?><span>Add at least one active employee before logging attendance.</span></div><?php endif; ?>
    </form>
<?php else: ?>
    <div class="banner info"><?= icon('info', 18) ?><span>Your role can view attendance but not change it.</span></div>
<?php endif; ?>
  </section>

  <!-- Records for the chosen date -->
  <section class="card">
    <div class="card-head">
      <h2><?= icon('clock', 24) ?> Records for <?= h(fmt_date($date, 'D, M j, Y')) ?></h2>
      <form method="get" class="head-actions">
        <a class="icon-btn" href="?date=<?= h($prev) ?>" aria-label="Previous day"><?= icon('chevron-left', 20) ?></a>
        <input class="input" style="width:auto;padding:8px 10px;border:1px solid var(--line);border-radius:10px" type="date" name="date" value="<?= h($date) ?>" data-autosubmit aria-label="Date">
        <a class="icon-btn" href="?date=<?= h($next) ?>" aria-label="Next day"><?= icon('chevron-right', 20) ?></a>
<?php if ($date !== $today): ?><a class="btn btn-ghost btn-sm" href="attendance.php">Today</a><?php endif; ?>
      </form>
    </div>

    <div class="legend" style="margin-bottom:16px">
<?php foreach ($counts as $label => $n): ?>
      <span class="badge <?= h(status_slug($label)) ?>"><?= h($label) ?>: <?= $n ?></span>
<?php endforeach; ?>
    </div>

<?php if (!$records): ?>
    <div class="empty"><?= icon('calendar', 44) ?><h3>No records for this date</h3><p>Use the form to log attendance, or try Bulk Entry to mark everyone at once.</p></div>
<?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Employee</th><th>Time In</th><th>Time Out</th><th>Status</th><th class="num">Action</th></tr></thead>
        <tbody>
<?php foreach ($records as $r): ?>
          <tr>
            <td><div class="cell-user"><?= avatar_html($r['full_name'], $r['photo'], 'sm') ?><div><strong><?= h($r['full_name']) ?></strong><small><?= h($r['employee_no']) ?><?= $r['notes'] !== '' ? ' · ' . h(mb_strimwidth($r['notes'], 0, 30, '…')) : '' ?></small></div></div></td>
            <td><?= h(fmt_time($r['time_in'])) ?></td>
            <td><?= h(fmt_time($r['time_out'])) ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="num"><div class="dropdown row-actions">
              <button class="icon-btn" type="button" data-dropdown aria-label="Row actions"><?= icon('more', 20) ?></button>
              <div class="dropdown-menu">
<?php if (can('attendance.write')): ?>
                <a href="attendance.php?date=<?= h($date) ?>&amp;edit=<?= (int)$r['id'] ?>"><?= icon('edit', 18) ?> Edit record</a>
<?php endif; ?>
<?php if (can('attendance.delete')): ?>
                <form method="post" data-confirm="<?= h('Delete the record for ' . $r['full_name'] . ' on ' . fmt_date($date) . '?') ?>" data-confirm-title="Delete record" data-confirm-btn="Delete" data-confirm-style="danger">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="date" value="<?= h($date) ?>">
                  <button class="item danger" type="submit"><?= icon('trash', 18) ?> Delete</button></form>
<?php endif; ?>
              </div>
            </div></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>
<?php if ($notLogged > 0): ?>
    <p class="muted small" style="margin-top:14px"><?= $notLogged ?> active employee<?= $notLogged === 1 ? ' has' : 's have' ?> no record on this date. <a href="attendance_bulk.php?date=<?= h($date) ?>">Mark them now →</a></p>
<?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
