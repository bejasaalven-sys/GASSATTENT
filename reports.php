<?php
/**
 * Reports
 *   reports.php                   detail rows for a date range
 *   reports.php?view=summary      one row per employee with totals
 *   reports.php?export=csv        download the current view as CSV
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
$user = require_login();
require_can('reports.view');
$pdo = db();
$today = date('Y-m-d');

/* ---------- Filters ---------- */
$view = input($_GET, 'view') === 'summary' ? 'summary' : 'detail';
$from = input($_GET, 'from', date('Y-m-01'));
$to = input($_GET, 'to', $today);
if (!valid_date($from)) $from = date('Y-m-01');
if (!valid_date($to)) $to = $today;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$empId = (int)($_GET['employee_id'] ?? 0);
$statusF = input($_GET, 'status');
if (!in_array($statusF, STATUSES, true)) $statusF = '';
$showAll = isset($_GET['all']);

$employees = $pdo->query('SELECT id, employee_no, full_name FROM employees ORDER BY full_name')->fetchAll();

/** Guard against spreadsheet formula injection in CSV cells. */
function csv_safe($v): string {
    $v = (string)$v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

/* ---------- Queries ---------- */
$where = 'a.attendance_date BETWEEN ? AND ?';
$params = [$from, $to];
if ($empId > 0) { $where .= ' AND a.employee_id = ?'; $params[] = $empId; }
if ($statusF !== '') { $where .= ' AND a.status = ?'; $params[] = $statusF; }

// Totals for the tiles (always over the full filtered range, not just one page)
$s = $pdo->prepare("SELECT a.status, COUNT(*) c FROM attendance a WHERE $where GROUP BY a.status");
$s->execute($params);
$counts = array_fill_keys(STATUSES, 0);
foreach ($s->fetchAll() as $r) { $counts[$r['status']] = (int)$r['c']; }
$totalRows = array_sum($counts);

$detailSql = "SELECT a.attendance_date, e.employee_no, e.full_name, a.status, a.time_in, a.time_out, a.notes
              FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE $where ORDER BY a.attendance_date DESC, e.full_name";

$summarySql = "SELECT e.employee_no, e.full_name, e.photo, e.archived_at,
                 COALESCE(SUM(a.status = 'Present'), 0) p, COALESCE(SUM(a.status = 'Late'), 0) l, COALESCE(SUM(a.status = 'Absent'), 0) ab,
                 COALESCE(SUM(a.status = 'On Leave'), 0) lv, COALESCE(SUM(a.status = 'Rest Day'), 0) rd, COUNT(a.id) total
               FROM employees e LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date BETWEEN ? AND ?"
             . ($statusF !== '' ? " AND a.status = ?" : '')
             . " WHERE (e.archived_at IS NULL OR a.id IS NOT NULL)" . ($empId > 0 ? ' AND e.id = ?' : '')
             . " GROUP BY e.id ORDER BY e.full_name";
$summaryParams = [$from, $to];
if ($statusF !== '') $summaryParams[] = $statusF;
if ($empId > 0) $summaryParams[] = $empId;

function attendance_pct(array $r): string {
    $worked = (int)$r['p'] + (int)$r['l'];
    $expected = $worked + (int)$r['ab'];
    return $expected > 0 ? round($worked / $expected * 100) . '%' : '—';
}

/* ---------- CSV export ---------- */
if (input($_GET, 'export') === 'csv') {
    $name = 'gasattend-' . ($view === 'summary' ? 'summary' : 'attendance') . '-' . $from . '_to_' . $to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                                  // UTF-8 BOM so Excel shows accents correctly
    if ($view === 'summary') {
        fputcsv($out, ['Employee No.', 'Employee Name', 'Present', 'Late', 'Absent', 'On Leave', 'Rest Day', 'Total Records', 'Attendance %']);
        $q = $pdo->prepare($summarySql);
        $q->execute($summaryParams);
        foreach ($q as $r) { fputcsv($out, [csv_safe($r['employee_no']), csv_safe($r['full_name']), $r['p'], $r['l'], $r['ab'], $r['lv'], $r['rd'], $r['total'], attendance_pct($r)]); }
    } else {
        fputcsv($out, ['Date', 'Employee No.', 'Employee Name', 'Status', 'Time In', 'Time Out', 'Notes']);
        $q = $pdo->prepare($detailSql);
        $q->execute($params);
        foreach ($q as $r) {
            fputcsv($out, [$r['attendance_date'], csv_safe($r['employee_no']), csv_safe($r['full_name']), $r['status'],
                           $r['time_in'] ? substr($r['time_in'], 0, 5) : '', $r['time_out'] ? substr($r['time_out'], 0, 5) : '', csv_safe($r['notes'])]);
        }
    }
    fclose($out);
    exit;
}

/* ---------- Page data ---------- */
$query = array_filter(['view' => $view === 'summary' ? 'summary' : '', 'from' => $from, 'to' => $to, 'employee_id' => $empId ?: '', 'status' => $statusF], fn($v) => $v !== '');
if ($view === 'summary') {
    $q = $pdo->prepare($summarySql);
    $q->execute($summaryParams);
    $rows = $q->fetchAll();
    $pg = null;
} else {
    $pg = $showAll ? ['page' => 1, 'pages' => 1, 'offset' => 0, 'per' => max(1, $totalRows), 'total' => $totalRows] : paginate($totalRows, (int)($_GET['page'] ?? 1), 15);
    $q = $pdo->prepare($detailSql . " LIMIT {$pg['per']} OFFSET {$pg['offset']}");
    $q->execute($params);
    $rows = $q->fetchAll();
}
$tileIcon = ['Present' => 'check', 'Late' => 'clock', 'Absent' => 'x', 'On Leave' => 'plane', 'Rest Day' => 'coffee'];

render_header($view === 'summary' ? 'Employee Summary' : 'Attendance Report', $view === 'summary' ? 'reports-summary' : 'reports');
?>
<div class="page-head">
  <div>
    <h1><?= $view === 'summary' ? 'Employee Summary' : 'Attendance Report' ?></h1>
    <p>Review attendance for a date range, then print or export it.</p>
  </div>
  <div class="head-actions no-print">
    <button class="btn btn-ghost" type="button" onclick="window.print()"><?= icon('printer', 18) ?> Print</button>
    <a class="btn btn-primary" href="?<?= h(http_build_query($query + ['export' => 'csv'])) ?>"><?= icon('download', 18) ?> Export CSV</a>
  </div>
</div>

<div class="sub-tabs no-print">
  <a class="<?= $view === 'detail' ? 'active' : '' ?>" href="?<?= h(http_build_query(array_diff_key($query, ['view' => 1]))) ?>">Attendance Details</a>
  <a class="<?= $view === 'summary' ? 'active' : '' ?>" href="?<?= h(http_build_query(['view' => 'summary'] + array_diff_key($query, ['view' => 1]))) ?>">Employee Summary</a>
</div>

<section class="card no-print" style="margin-bottom:22px">
  <form class="filters" method="get" style="margin-bottom:0" data-validate novalidate>
    <?php if ($view === 'summary'): ?><input type="hidden" name="view" value="summary"><?php endif; ?>
    <div class="field" data-field><label for="r-from">From</label><input id="r-from" type="date" name="from" value="<?= h($from) ?>" required></div>
    <div class="field" data-field><label for="r-to">To</label><input id="r-to" type="date" name="to" value="<?= h($to) ?>" required></div>
    <div class="field"><label for="r-emp">Employee</label>
      <select id="r-emp" name="employee_id">
        <option value="0">All employees</option>
<?php foreach ($employees as $e): ?>
        <option value="<?= (int)$e['id'] ?>" <?= $empId === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['full_name']) ?></option>
<?php endforeach; ?>
      </select></div>
    <div class="field"><label for="r-status">Status</label>
      <select id="r-status" name="status">
        <option value="">All statuses</option>
<?php foreach (STATUSES as $st): ?>
        <option <?= $statusF === $st ? 'selected' : '' ?>><?= h($st) ?></option>
<?php endforeach; ?>
      </select></div>
    <button class="btn btn-primary" type="submit"><?= icon('search', 18) ?> Generate</button>
    <a class="btn btn-ghost" href="reports.php<?= $view === 'summary' ? '?view=summary' : '' ?>">Reset</a>
  </form>
</section>

<div class="report-title"><h2>GASATTEND Attendance Report</h2><p>Period: <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?> · Generated <?= h(date('M j, Y g:i A')) ?></p></div>

<section class="status-grid" style="margin-bottom:22px">
<?php foreach ($counts as $label => $c): $pct = $totalRows > 0 ? round($c / $totalRows * 100, 1) : 0; ?>
  <article class="tile <?= h(status_slug($label)) ?>">
    <div class="t-top"><span class="t-ico"><?= icon($tileIcon[$label], 18) ?></span><?= h($label) ?></div>
    <div class="t-num"><?= number_format($c) ?></div>
    <div class="t-pct"><?= $pct ?>%</div>
    <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
  </article>
<?php endforeach; ?>
</section>

<section class="card">
  <div class="card-head">
    <h2><?= icon($view === 'summary' ? 'users' : 'calendar-check', 24) ?> <?= $view === 'summary' ? 'Totals per employee' : 'Attendance details' ?></h2>
    <span class="muted"><?= $view === 'summary' ? count($rows) . ' employee(s)' : $totalRows . ' record(s)' ?> · <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?></span>
  </div>
<?php if (!$rows): ?>
  <div class="empty"><?= icon('bar-chart', 44) ?><h3>No records found</h3><p>Try a wider date range or remove a filter.</p></div>
<?php elseif ($view === 'summary'): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Employee</th><th class="num">Present</th><th class="num">Late</th><th class="num">Absent</th><th class="num">On Leave</th><th class="num">Rest Day</th><th class="num">Total</th><th class="num" title="(Present + Late) / (Present + Late + Absent)">Attendance %</th></tr></thead>
      <tbody>
<?php foreach ($rows as $r): ?>
        <tr>
          <td><div class="cell-user"><?= avatar_html($r['full_name'], $r['photo'], 'sm') ?><div><strong><?= h($r['full_name']) ?></strong><small><?= h($r['employee_no']) ?><?= $r['archived_at'] ? ' · archived' : '' ?></small></div></div></td>
          <td class="num"><?= (int)$r['p'] ?></td><td class="num"><?= (int)$r['l'] ?></td><td class="num"><?= (int)$r['ab'] ?></td>
          <td class="num"><?= (int)$r['lv'] ?></td><td class="num"><?= (int)$r['rd'] ?></td><td class="num"><strong><?= (int)$r['total'] ?></strong></td>
          <td class="num"><strong><?= h(attendance_pct($r)) ?></strong></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted small" style="margin-top:12px">Attendance % = (Present + Late) ÷ (Present + Late + Absent). Leave and rest days are not counted against an employee.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Date</th><th>Employee</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>
<?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h(fmt_date($r['attendance_date'])) ?></td>
          <td><strong><?= h($r['full_name']) ?></strong><br><small class="muted"><?= h($r['employee_no']) ?></small></td>
          <td><?= h(fmt_time($r['time_in'])) ?></td><td><?= h(fmt_time($r['time_out'])) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td><?= h($r['notes'] ?: '—') ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="no-print"><?= render_pager($pg, $query) ?></div>
<?php if (!$showAll && $pg['pages'] > 1): ?>
  <p class="small no-print" style="margin-top:8px"><a href="?<?= h(http_build_query($query + ['all' => 1])) ?>">Show all rows on one page (useful before printing)</a></p>
<?php endif; ?>
<?php endif; ?>
</section>
<?php render_footer(); ?>
