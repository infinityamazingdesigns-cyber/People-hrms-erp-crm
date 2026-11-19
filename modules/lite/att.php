<?php
// Lite attendance: the login's own month, day by day (same day lines as the full register).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/lite.php';
require_once WN_ROOT . '/lib/attendance.php';
$u = module_require_self('lite');
$db = pdo(); $emp = lite_emp($db, $u);
lite_head('Attendance', $u);
if (!module_allowed($u, 'attendance') || !$emp) { echo '<p class="e">' . (!$emp ? 'Your login is not linked to an employee record yet — ask HR.' : 'Attendance is not open for you.') . '</p>'; lite_foot($u); exit; }
$ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
$from = $ym . '-01'; $to = min(date('Y-m-t', strtotime($from)), date('Y-m-d'));
$days = $from <= $to ? att_days_for($db, $emp, $from, $to) : []; $S = att_summary($days);
$prev = date('Y-m', strtotime($from . ' -1 month')); $nx = date('Y-m', strtotime($from . ' +1 month')); ?>
<div class="c"><a href="?m=<?= $prev ?>">‹</a> <b><?= h(date('F Y', strtotime($from))) ?></b> <?php if ($nx <= date('Y-m')): ?><a href="?m=<?= $nx ?>">›</a><?php endif; ?>
<p class="m">Present <?= $S['Present'] ?> · half <?= $S['Half day'] ?> · absent <?= $S['Absent'] ?> · missed punch <?= $S['Missed punch'] ?> · leave <?= $S['Leave days'] ?> · off <?= $S['Weekly off'] + $S['Holiday'] ?></p>
<table><?php foreach (array_reverse($days) as $d => $l): ?><tr><td><?= h(date('D j', strtotime($d))) ?></td><td><?= h(lite_day($l)) ?><?php if ($l['status'] === 'Missed punch' || $l['status'] === 'Absent'): ?> <a href="req.php?day=<?= h($d) ?>">correct</a><?php endif; ?></td></tr><?php endforeach; ?></table></div>
<?php lite_foot($u);
