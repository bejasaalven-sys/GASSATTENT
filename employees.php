<?php
/**
 * Employees: list, search, filter and row actions.
 *   Create / Edit  -> employee_form.php
 *   Activate/Deactivate, Archive, Restore, Delete (permanent) -> POST actions below
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
$user = require_login();
require_can('employees.view');
$pdo = db();

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input($_POST, 'action');
    $id = (int)($_POST['id'] ?? 0);

    // Return to the same filtered list afterwards.
    parse_str((string)($_POST['qs'] ?? ''), $prev);
    $keep = [];
    foreach (['q', 'status', 'dept', 'page'] as $k) { if (isset($prev[$k]) && is_string($prev[$k])) $keep[$k] = $prev[$k]; }
    $back = 'employees.php' . ($keep ? '?' . http_build_query($keep) : '');

    $s = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $s->execute([$id]);
    $emp = $s->fetch();
    if (!$emp) { flash('Employee not found.', 'error'); redirect($back); }

    if ($action === 'toggle') {
        require_can('employees.write');
        $pdo->prepare('UPDATE employees SET active = 1 - active WHERE id = ?')->execute([$id]);
        flash($emp['full_name'] . ((int)$emp['active'] === 1 ? ' is now inactive.' : ' is now active.'));
    } elseif ($action === 'archive') {
        require_can('employees.archive');
        $pdo->prepare('UPDATE employees SET archived_at = NOW() WHERE id = ?')->execute([$id]);
        flash($emp['full_name'] . ' was archived. Attendance history is kept.', 'warning');
    } elseif ($action === 'restore') {
        require_can('employees.archive');
        $pdo->prepare('UPDATE employees SET archived_at = NULL WHERE id = ?')->execute([$id]);
        flash($emp['full_name'] . ' was restored.');
    } elseif ($action === 'delete') {
        require_can('employees.purge');
        $c = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id = ?');
        $c->execute([$id]);
        if ($emp['archived_at'] === null) {
            flash('Archive the employee first, then delete.', 'error');
        } elseif ((int)$c->fetchColumn() > 0) {
            flash($emp['full_name'] . ' has attendance records and cannot be deleted. Keep them archived instead.', 'error');
        } else {
            $pdo->prepare('DELETE FROM employees WHERE id = ?')->execute([$id]);
            delete_employee_photo($emp['photo']);
            flash($emp['full_name'] . ' was permanently deleted.');
        }
    }
    redirect($back);
}

/* ---------- Filters ---------- */
$q = input($_GET, 'q');
$status = input($_GET, 'status', 'all');
if (!in_array($status, ['all', 'active', 'inactive', 'archived'], true)) $status = 'all';
$dept = input($_GET, 'dept');

$where = [];
$params = [];
$where[] = $status === 'archived' ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
if ($status === 'active') $where[] = 'active = 1';
if ($status === 'inactive') $where[] = 'active = 0';
if ($q !== '') {
    $where[] = '(full_name LIKE ? OR employee_no LIKE ? OR position LIKE ? OR email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($dept !== '') { $where[] = 'department = ?'; $params[] = $dept; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$c = $pdo->prepare("SELECT COUNT(*) FROM employees $whereSql");
$c->execute($params);
$pg = paginate((int)$c->fetchColumn(), (int)($_GET['page'] ?? 1));

$s = $pdo->prepare("SELECT * FROM employees $whereSql ORDER BY full_name LIMIT {$pg['per']} OFFSET {$pg['offset']}");
$s->execute($params);
$rows = $s->fetchAll();

$tabs = $pdo->query("SELECT SUM(archived_at IS NULL) AS everyone, SUM(archived_at IS NULL AND active = 1) AS act,
                            SUM(archived_at IS NULL AND active = 0) AS inact, SUM(archived_at IS NOT NULL) AS arch FROM employees")->fetch();
$departments = $pdo->query("SELECT DISTINCT department FROM employees WHERE department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
$qs = $_SERVER['QUERY_STRING'] ?? '';
$baseQuery = array_filter(['q' => $q, 'status' => $status === 'all' ? '' : $status, 'dept' => $dept], fn($v) => $v !== '');

render_header('Employees', 'employees');
?>
<div class="page-head">
  <div>
    <h1>Employees</h1>
    <p>Manage your team members and their details.</p>
  </div>
  <div class="head-actions">
<?php if (can('employees.write')): ?>
    <a class="btn btn-primary" href="employee_form.php"><?= icon('user-plus', 18) ?> Add Employee</a>
<?php endif; ?>
  </div>
</div>

<div class="chips">
<?php foreach (['all' => ['All', $tabs['everyone']], 'active' => ['Active', $tabs['act']], 'inactive' => ['Inactive', $tabs['inact']], 'archived' => ['Archived', $tabs['arch']]] as $key => [$label, $n]): ?>
  <a class="chip <?= $status === $key ? 'active' : '' ?>" href="?<?= h(http_build_query(array_filter(['status' => $key === 'all' ? '' : $key, 'q' => $q, 'dept' => $dept], fn($v) => $v !== ''))) ?>"><?= h($label) ?> (<?= (int)$n ?>)</a>
<?php endforeach; ?>
</div>

<section class="card">
  <form class="filters" method="get">
    <input type="hidden" name="status" value="<?= h($status === 'all' ? '' : $status) ?>">
    <div class="field grow"><label for="q">Search</label><input id="q" type="search" name="q" value="<?= h($q) ?>" placeholder="Name, employee no., position or email"></div>
    <div class="field"><label for="dept">Department</label>
      <select id="dept" name="dept" data-autosubmit>
        <option value="">All departments</option>
<?php foreach ($departments as $d): ?>
        <option value="<?= h($d) ?>" <?= $dept === $d ? 'selected' : '' ?>><?= h($d) ?></option>
<?php endforeach; ?>
      </select></div>
    <button class="btn btn-primary" type="submit"><?= icon('search', 18) ?> Search</button>
<?php if ($q !== '' || $dept !== ''): ?><a class="btn btn-ghost" href="employees.php<?= $status !== 'all' ? '?status=' . h($status) : '' ?>">Clear</a><?php endif; ?>
  </form>

<?php if (!$rows): ?>
  <div class="empty"><?= icon('users', 44) ?><h3>No employees found</h3><p><?= $q !== '' || $dept !== '' ? 'Try a different search or filter.' : 'Add your first team member to get started.' ?></p></div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>Employee</th><th>Position</th><th>Contact</th><th>Hired</th><th>Status</th><th class="num">Action</th></tr></thead>
      <tbody>
<?php foreach ($rows as $i => $e): $isArchived = $e['archived_at'] !== null; ?>
        <tr>
          <td><?= $pg['offset'] + $i + 1 ?></td>
          <td><div class="cell-user"><?= avatar_html($e['full_name'], $e['photo'], 'sm') ?><div><strong><?= h($e['full_name']) ?></strong><small><?= h($e['employee_no']) ?> · <?= h($e['employment_type']) ?></small></div></div></td>
          <td><?= h($e['position'] ?: '—') ?><?php if ($e['department']): ?><br><small class="muted"><?= h($e['department']) ?></small><?php endif; ?></td>
          <td><?= h($e['email'] ?: '—') ?><?php if ($e['phone']): ?><br><small class="muted"><?= h($e['phone']) ?></small><?php endif; ?></td>
          <td><?= h(fmt_date($e['hire_date'])) ?></td>
          <td><?php if ($isArchived): ?><span class="badge archived">Archived</span><?php else: ?><span class="badge <?= (int)$e['active'] === 1 ? 'active' : 'inactive' ?>"><?= (int)$e['active'] === 1 ? 'Active' : 'Inactive' ?></span><?php endif; ?></td>
          <td class="num"><div class="dropdown row-actions">
            <button class="icon-btn" type="button" data-dropdown aria-label="Actions for <?= h($e['full_name']) ?>"><?= icon('more', 20) ?></button>
            <div class="dropdown-menu">
<?php if (can('employees.write')): ?>
              <a href="employee_form.php?id=<?= (int)$e['id'] ?>"><?= icon('edit', 18) ?> Edit details</a>
<?php endif; ?>
              <a href="reports.php?employee_id=<?= (int)$e['id'] ?>"><?= icon('bar-chart', 18) ?> Attendance history</a>
<?php if (!$isArchived && can('employees.write')): ?>
              <form method="post" data-confirm="<?= h(((int)$e['active'] === 1 ? 'Mark ' : 'Reactivate ') . $e['full_name'] . ((int)$e['active'] === 1 ? ' as inactive?' : '?')) ?>" data-confirm-title="<?= (int)$e['active'] === 1 ? 'Deactivate employee' : 'Activate employee' ?>" data-confirm-btn="Yes, continue" data-confirm-style="primary">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="qs" value="<?= h($qs) ?>">
                <button class="item" type="submit"><?= icon('check-circle', 18) ?> <?= (int)$e['active'] === 1 ? 'Set inactive' : 'Set active' ?></button>
              </form>
<?php endif; ?>
<?php if (can('employees.archive')): ?>
              <hr>
<?php if ($isArchived): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="qs" value="<?= h($qs) ?>">
                <button class="item" type="submit"><?= icon('restore', 18) ?> Restore</button></form>
<?php else: ?>
              <form method="post" data-confirm="<?= h($e['full_name'] . ' will be hidden from lists and attendance entry. Past attendance records are kept.') ?>" data-confirm-title="Archive employee" data-confirm-btn="Archive" data-confirm-style="danger">
                <?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="qs" value="<?= h($qs) ?>">
                <button class="item danger" type="submit"><?= icon('archive', 18) ?> Archive</button></form>
<?php endif; ?>
<?php endif; ?>
<?php if ($isArchived && can('employees.purge')): ?>
              <form method="post" data-confirm="<?= h('Permanently delete ' . $e['full_name'] . '? This cannot be undone. Employees with attendance records cannot be deleted.') ?>" data-confirm-title="Delete permanently" data-confirm-btn="Delete" data-confirm-style="danger">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="qs" value="<?= h($qs) ?>">
                <button class="item danger" type="submit"><?= icon('trash', 18) ?> Delete permanently</button></form>
<?php endif; ?>
            </div>
          </div></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= render_pager($pg, $baseQuery) ?>
<?php endif; ?>
</section>
<?php render_footer(); ?>
