<?php
/** Bulk entry: set the status of many employees for one date in a single save. */
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
$user = require_login();
require_can('attendance.write');
$pdo = db();
$today = date('Y-m-d');

$date = input($_GET, 'date', $today);
if (!valid_date($date)) $date = $today;

$employees = $pdo->query('SELECT id, employee_no, full_name, photo FROM employees WHERE active = 1 AND archived_at IS NULL ORDER BY full_name')->fetchAll();
$validIds = array_map('intval', array_column($employees, 'id'));
$posted = null;
$problems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $date = input($_POST, 'attendance_date', $date);
    if (!valid_date($date)) $date = $today;
    $posted = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
    $names = array_column($employees, 'full_name', 'id');
    $toSave = [];

    foreach ($posted as $empId => $row) {
        $empId = (int)$empId;
        if (!is_array($row) || !in_array($empId, $validIds, true)) continue;
        $status = isset($row['status']) && is_string($row['status']) ? trim($row['status']) : '';
        if ($status === '') continue;                                   // "skip" rows are not saved
        $tin = isset($row['time_in']) && is_string($row['time_in']) ? trim($row['time_in']) : '';
        $tout = isset($row['time_out']) && is_string($row['time_out']) ? trim($row['time_out']) : '';
        $name = $names[$empId] ?? ('#' . $empId);

        if (!in_array($status, STATUSES, true)) { $problems[] = "$name: invalid status."; continue; }
        if (!in_array($status, ['Present', 'Late'], true)) { $tin = ''; $tout = ''; }
        elseif ($tin === '') { $problems[] = "$name: time in is required for $status."; continue; }
        if (($tin !== '' && !valid_time($tin)) || ($tout !== '' && !valid_time($tout))) { $problems[] = "$name: use HH:MM times."; continue; }
        if ($tin !== '' && $tout !== '' && $tout < $tin) { $problems[] = "$name: time out is earlier than time in."; continue; }
        if ($date > $today && in_array($status, ['Present', 'Late', 'Absent'], true)) { $problems[] = "$name: future dates can only be Leave or Rest Day."; continue; }
        $toSave[] = [$empId, $status, $tin !== '' ? $tin : null, $tout !== '' ? $tout : null];
    }

    if ($problems) {
        flash('Nothing was saved. ' . implode(' ', array_slice($problems, 0, 3)) . (count($problems) > 3 ? ' (+' . (count($problems) - 3) . ' more)' : ''), 'error');
    } elseif (!$toSave) {
        flash('Choose a status for at least one employee.', 'warning');
    } else {
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO attendance (employee_id, attendance_date, status, time_in, time_out, notes, created_by) VALUES (?,?,?,?,?,?,?)
                                 ON DUPLICATE KEY UPDATE status=VALUES(status), time_in=VALUES(time_in), time_out=VALUES(time_out), created_by=VALUES(created_by)');
            foreach ($toSave as [$eid, $status, $tin, $tout]) { $st->execute([$eid, $date, $status, $tin, $tout, '', $user['id']]); }
            $pdo->commit();
            flash(count($toSave) . ' attendance record' . (count($toSave) === 1 ? '' : 's') . ' saved for ' . fmt_date($date) . '.');
            redirect('attendance.php?date=' . urlencode($date));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('Could not save the records. Please try again.', 'error');
        }
    }
}

/* Existing records for the chosen date (used to pre-fill the rows) */
$s = $pdo->prepare('SELECT employee_id, status, time_in, time_out FROM attendance WHERE attendance_date = ?');
$s->execute([$date]);
$existing = [];
foreach ($s->fetchAll() as $r) { $existing[(int)$r['employee_id']] = $r; }

render_header('Bulk Attendance Entry', 'attendance-bulk');
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="attendance.php">Attendance</a> <?= icon('chevron-right', 14) ?> <span>Bulk Entry</span></div>
    <h1>Bulk Entry</h1>
    <p>Set the status for many employees at once. Rows left on “— skip —” are not saved.</p>
  </div>
  <form method="get" class="head-actions">
    <label class="date-pill"><?= icon('calendar', 20) ?><input type="date" name="date" value="<?= h($date) ?>" data-autosubmit aria-label="Date"></label>
  </form>
</div>

<form method="post" data-bulk-form>
  <?= csrf_field() ?><input type="hidden" name="attendance_date" value="<?= h($date) ?>">
  <section class="card">
    <div class="filters">
      <div class="field"><label for="b-in">Default time in</label><input id="b-in" type="time" value="08:00" data-bulk-in></div>
      <div class="field"><label for="b-out">Default time out</label><input id="b-out" type="time" value="17:00" data-bulk-out></div>
      <button type="button" class="btn btn-green" data-bulk-fill="Present"><?= icon('check', 18) ?> Mark unset as Present</button>
      <button type="button" class="btn btn-ghost" data-bulk-fill="Rest Day"><?= icon('coffee', 18) ?> Unset → Rest Day</button>
      <button type="button" class="btn btn-ghost" data-bulk-clear>Clear unsaved</button>
    </div>

<?php if (!$employees): ?>
    <div class="empty"><?= icon('users', 44) ?><h3>No active employees</h3><p>Add employees first, then come back to log attendance.</p></div>
<?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Employee</th><th>Status</th><th>Time In</th><th>Time Out</th><th>Saved</th></tr></thead>
        <tbody>
<?php foreach ($employees as $e):
        $id = (int)$e['id'];
        $ex = $existing[$id] ?? null;
        $p = is_array($posted[$id] ?? null) ? $posted[$id] : null;
        $status = $p ? (string)($p['status'] ?? '') : ($ex['status'] ?? '');
        $tin = $p ? (string)($p['time_in'] ?? '') : substr((string)($ex['time_in'] ?? ''), 0, 5);
        $tout = $p ? (string)($p['time_out'] ?? '') : substr((string)($ex['time_out'] ?? ''), 0, 5);
?>
          <tr data-row data-saved="<?= $ex ? '1' : '0' ?>">
            <td><div class="cell-user"><?= avatar_html($e['full_name'], $e['photo'], 'sm') ?><div><strong><?= h($e['full_name']) ?></strong><small><?= h($e['employee_no']) ?></small></div></div></td>
            <td><select class="input" style="padding:8px 10px;border:1px solid #d5ddee;border-radius:10px" name="rows[<?= $id ?>][status]" data-row-status aria-label="Status for <?= h($e['full_name']) ?>">
              <option value="">— skip —</option>
<?php foreach (STATUSES as $st): ?>
              <option <?= $status === $st ? 'selected' : '' ?>><?= h($st) ?></option>
<?php endforeach; ?>
            </select></td>
            <td><input type="time" style="padding:8px 10px;border:1px solid #d5ddee;border-radius:10px" name="rows[<?= $id ?>][time_in]" value="<?= h($tin) ?>" data-row-in aria-label="Time in"></td>
            <td><input type="time" style="padding:8px 10px;border:1px solid #d5ddee;border-radius:10px" name="rows[<?= $id ?>][time_out]" value="<?= h($tout) ?>" data-row-out aria-label="Time out"></td>
            <td><?= $ex ? status_badge($ex['status']) : '<span class="muted small">Not logged</span>' ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="form-actions" style="margin-top:18px">
      <a class="btn btn-ghost" href="attendance.php?date=<?= h($date) ?>">Cancel</a>
      <button class="btn btn-primary" type="submit"><?= icon('check', 18) ?> Save All</button>
    </div>
<?php endif; ?>
  </section>
</form>
<?php render_footer(); ?>
