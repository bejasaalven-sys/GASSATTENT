<?php
/** Help & Support: quick guide, status meanings, and FAQ. */
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/layout.php';
$user = require_login();

$meaning = [
    'Present'  => 'The employee reported for work on time.',
    'Late'     => 'The employee reported after the shift start (' . WORK_START . '). Late still counts toward the attendance rate.',
    'Absent'   => 'The employee did not report and has no approved leave. This is always entered manually.',
    'On Leave' => 'The employee is on approved leave (vacation, sick, etc.).',
    'Rest Day' => 'A scheduled day off. Rest days are not counted against the employee.',
];

render_header('Help & Support', 'help');
?>
<div class="page-head">
  <div><h1>Help &amp; Support</h1><p>Quick answers for everyday tasks in gasattend.</p></div>
</div>

<div class="two-col" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
  <section class="card">
    <div class="card-head"><h2><?= icon('calendar-check', 24) ?> Attendance statuses</h2></div>
    <div class="faq">
<?php foreach ($meaning as $label => $text): ?>
      <details open><summary><?= status_badge($label) ?></summary><p><?= h($text) ?></p></details>
<?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= icon('help', 24) ?> Frequently asked questions</h2></div>
    <div class="faq">
      <details><summary>How do I log attendance for one person?</summary><p>Open <a href="attendance.php">Attendance → Daily Log</a>, pick the employee, date and status, then save. Saving again for the same employee and date updates that day's record.</p></details>
      <details><summary>How do I log a whole team quickly?</summary><p>Use <a href="attendance_bulk.php">Bulk Entry</a>. Set a status for each person (or use “Mark unset as Present”), then press Save All.</p></details>
      <details><summary>What is the difference between inactive and archived?</summary><p>Inactive employees stay in lists but cannot be logged. Archived employees are hidden everywhere (their history is kept) and can be restored. Only administrators can permanently delete an archived employee, and only if they have no attendance records.</p></details>
      <details><summary>How is the attendance rate calculated?</summary><p>Dashboard: (Present + Late today) ÷ active employees. Employee Summary: (Present + Late) ÷ (Present + Late + Absent) for the chosen dates.</p></details>
      <details><summary>How do I export data?</summary><p>Open <a href="reports.php">Reports</a>, choose your filters and press Export CSV. The file opens in Excel or Google Sheets.</p></details>
      <details><summary>I forgot my password.</summary><p>Ask an administrator to reset it in <strong>Settings → Users &amp; Roles</strong>. They will give you a temporary password to change after signing in.</p></details>
    </div>
  </section>
</div>
<?php render_footer(); ?>
