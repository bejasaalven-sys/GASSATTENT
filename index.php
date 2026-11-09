<?php
/** Dashboard: headline numbers, recent records, quick actions, status overview. */
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
$user = require_login();

$pdo = db();
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$monthStart = date('Y-m-01');

/* --- Employee counts (archived employees are excluded everywhere) --- */
$total = (int)$pdo->query('SELECT COUNT(*) FROM employees WHERE archived_at IS NULL')->fetchColumn();
$active = (int)$pdo->query('SELECT COUNT(*) FROM employees WHERE archived_at IS NULL AND active = 1')->fetchColumn();
$s = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE archived_at IS NULL AND created_at >= ?');
$s->execute([$monthStart]);
$newThisMonth = (int)$s->fetchColumn();

/* --- Attendance rate: (Present + Late) / active employees --- */
function present_count(PDO $pdo, string $date): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id
                        WHERE a.attendance_date = ? AND a.status IN ('Present','Late') AND e.archived_at IS NULL");
    $s->execute([$date]);
    return (int)$s->fetchColumn();
}
$presentToday = present_count($pdo, $today);
$rate = $active > 0 ? (int)round($presentToday / $active * 100) : 0;
$rateYesterday = $active > 0 ? (int)round(present_count($pdo, $yesterday) / $active * 100) : 0;
$rateDiff = $rate - $rateYesterday;

/* --- Recent attendance records --- */
$recent = $pdo->query("SELECT a.id, a.attendance_date, a.status, a.time_in, e.full_name, e.employee_no, e.photo
                       FROM attendance a JOIN employees e ON e.id = a.employee_id
                       ORDER BY a.attendance_date DESC, a.updated_at DESC, a.id DESC LIMIT 5")->fetchAll();

/* --- Status overview (period selectable) --- */
$period = input($_GET, 'period', 'month');
if (!in_array($period, ['today', 'week', 'month'], true)) $period = 'month';
$from = $period === 'today' ? $today : ($period === 'week' ? date('Y-m-d', strtotime('-6 days')) : $monthStart);
$s = $pdo->prepare('SELECT status, COUNT(*) AS c FROM attendance WHERE attendance_date BETWEEN ? AND ? GROUP BY status');
$s->execute([$from, $today]);
$counts = array_fill_keys(STATUSES, 0);
foreach ($s->fetchAll() as $r) { if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c']; }
$sumCounts = array_sum($counts);
$tileIcon = ['Present' => 'check', 'Late' => 'clock', 'Absent' => 'x', 'On Leave' => 'plane', 'Rest Day' => 'coffee'];

render_header('Dashboard', 'dashboard');
?>
<div class="page-head">
  <div>
    <h1>Dashboard</h1>
    <p>Here's what's happening with your team today.</p>
  </div>
  <form action="attendance.php" method="get">
    <label class="date-pill" title="Jump to a date in the attendance log"><?= icon('calendar', 20) ?>
      <input type="date" name="date" value="<?= h($today) ?>" data-autosubmit aria-label="Choose date"></label>
  </form>
</div>

<section class="grid-stats">
  <article class="stat-card blue">
    <span class="s-ico"><?= icon('users', 42) ?></span>
    <div>
      <h3>Total Employees</h3>
      <span class="num"><?= $total ?></span>
      <span class="trend <?= $newThisMonth === 0 ? 'flat' : '' ?>"><?= $newThisMonth > 0 ? '↑ +' . $newThisMonth . ' new this month' : 'No new hires this month' ?></span>
    </div>
  </article>
  <article class="stat-card green">
    <span class="s-ico"><?= icon('check-circle', 42) ?></span>
    <div>
      <h3>Active Employees</h3>
      <span class="num"><?= $active ?></span>
      <span class="trend flat"><?= $total - $active ?> inactive</span>
    </div>
  </article>
  <article class="stat-card rate-card">
    <div class="ring" style="--p:<?= $rate ?>"><span><?= $rate ?>%</span></div>
    <div>
      <h3>Today's Attendance Rate</h3>
      <span class="num"><?= $rate ?>%</span>
      <span class="trend <?= $rateDiff < 0 ? 'down' : '' ?>"><?= $rateDiff > 0 ? '↑ +' . $rateDiff . '% vs yesterday' : ($rateDiff < 0 ? '↓ ' . $rateDiff . '% vs yesterday' : 'Same as yesterday') ?></span>
    </div>
  </article>
</section>

<section class="grid-main">
  <article class="card">
    <div class="card-head">
      <h2><?= icon('clock', 26) ?> Recent Attendance Records</h2>
      <a class="link" href="attendance.php">View All <?= icon('arrow-right', 16) ?></a>
    </div>
<?php if (!$recent): ?>
    <div class="empty"><?= icon('calendar-check', 44) ?><h3>No attendance records yet</h3><p>Log today's attendance to see it here.</p></div>
<?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>#</th><th>Name</th><th>Date</th><th>Status</th><th>Time In</th><th class="num">Action</th></tr></thead>
        <tbody>
<?php foreach ($recent as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><strong><?= h($r['full_name']) ?></strong></td>
            <td><?= h(fmt_date($r['attendance_date'])) ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td><?= h(fmt_time($r['time_in'])) ?></td>
            <td class="num"><div class="dropdown row-actions">
              <button class="icon-btn" type="button" data-dropdown aria-label="Row actions"><?= icon('more', 20) ?></button>
              <div class="dropdown-menu">
                <a href="attendance.php?date=<?= h($r['attendance_date']) ?>&amp;edit=<?= (int)$r['id'] ?>"><?= icon('edit', 18) ?> Edit record</a>
                <a href="attendance.php?date=<?= h($r['attendance_date']) ?>"><?= icon('calendar', 18) ?> View that day</a>
              </div>
            </div></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>
  </article>

  <aside class="card">
    <div class="card-head"><h2><?= icon('zap', 26) ?> Quick Actions</h2></div>
    <div class="quick">
<?php if (can('employees.write')): ?>
      <a class="q-blue" href="employee_form.php"><?= icon('user-plus', 30) ?><span><strong>Add Employee</strong><small>Register a new team member</small></span><span class="arrow"><?= icon('arrow-right', 22) ?></span></a>
<?php endif; ?>
      <a class="q-green" href="attendance.php"><?= icon('calendar-check', 30) ?><span><strong>Log Attendance</strong><small>Record attendance manually</small></span><span class="arrow"><?= icon('arrow-right', 22) ?></span></a>
      <a class="q-purple" href="reports.php?export=csv"><?= icon('download', 30) ?><span><strong>Export CSV Report</strong><small>Download attendance data</small></span><span class="arrow"><?= icon('arrow-right', 22) ?></span></a>
    </div>
  </aside>
</section>

<section class="card">
  <div class="card-head">
    <h2><?= icon('bar-chart', 24) ?> Attendance Status Overview</h2>
    <form method="get"><select class="pill" name="period" data-autosubmit aria-label="Period">
      <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
      <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Last 7 Days</option>
      <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This Month</option>
    </select></form>
  </div>
  <div class="status-grid">
<?php foreach ($counts as $label => $c): $pct = $sumCounts > 0 ? round($c / $sumCounts * 100, 1) : 0; ?>
    <article class="tile <?= h(status_slug($label)) ?>">
      <div class="t-top"><span class="t-ico"><?= icon($tileIcon[$label], 18) ?></span><?= h($label) ?></div>
      <div class="t-num"><?= number_format($c) ?></div>
      <div class="t-pct"><?= $pct ?>%</div>
      <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
    </article>
<?php endforeach; ?>
  </div>
</section>
<?php render_footer(); ?>
