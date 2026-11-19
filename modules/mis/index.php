<?php
// HR dashboard (MIS) — read only, counts only, cut to the viewer's branches. Rules in lib/mis.php.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/mis.php';
$u = module_require('mis');
$db = pdo(); $uid = (int)$u['id']; $isAdmin = has_role($u, 'SUPERADMIN'); $set = mis_settings(); $today = date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
        $st = module_states()['mis'] ?? ['roles' => module_roles('mis')];
        $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
           ->execute(['mis', 1, implode(',', $st['roles'] ?: module_roles('mis')), json_encode(mis_settings_check($_POST)), $uid]);
        audit($uid, 'module_settings', 'mis'); flash('Settings saved.');
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php?view=settings');
}
$view = (string)($_GET['view'] ?? 'dash');
ui_head('HR dashboard (MIS)', $u, 'mis');
$tile = fn(string $label, $n, string $note = '') => '<div class="card" style="min-width:9em"><div class="muted">' . h($label) . '</div><div style="font-size:1.6em;font-weight:600">' . h((string)$n) . '</div>' . ($note !== '' ? '<div class="muted">' . h($note) . '</div>' : '') . '</div>';
$table = fn(array $rows, string $a, string $b) => '<table><tr><th>' . h($a) . '</th><th>' . h($b) . '</th></tr>' . implode('', array_map(fn($k, $v) => '<tr><td>' . h((string)$k) . '</td><td>' . h((string)$v) . '</td></tr>', array_keys($rows), $rows)) . ($rows ? '' : '<tr><td colspan="2" class="muted">Nothing recorded yet.</td></tr>') . '</table>'; ?>
<?php if ($isAdmin): ?><nav class="row"><a class="btn sm<?= $view === 'dash' ? '' : ' ghost' ?>" href="index.php">Dashboard</a> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a></nav><?php endif; ?>
<?php if ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><h2>Settings</h2><div class="grid">
<label>Months in the trend <input type="number" name="trend_months" value="<?= (int)$set['trend_months'] ?>" min="3" max="24"></label>
<label>Months for attrition and retention <input type="number" name="attrition_months" value="<?= (int)$set['attrition_months'] ?>" min="3" max="24"></label>
<label>Probation ending within (days) <input type="number" name="probation_days" value="<?= (int)$set['probation_days'] ?>" min="7" max="90"></label>
<label>Statutory dues within (days) <input type="number" name="dues_days" value="<?= (int)$set['dues_days'] ?>" min="7" max="90"></label></div><button class="btn">Save</button></form>
<?php else: $P = mis_payload($db, $u, $today); $S = $P['sections']; $scope = emp_scope($u); ?>
<p class="muted"><?= $scope === null ? 'Every branch' : 'Your branches' ?> · counts only, as on <?= h(date('j M Y', strtotime($today))) ?></p>
<div class="row" style="flex-wrap:wrap;align-items:stretch"><?= $tile('On the roll', $P['roll']) . $tile('Joined this month', $P['moves']['joined']) . $tile('Left this month', $P['moves']['left']) . $tile('On notice', $P['notice'])
 . $tile('Attrition', $P['att']['attrition'] === null ? '—' : $P['att']['attrition'] . '%', 'last ' . (int)$set['attrition_months'] . ' months, ' . $P['att']['left'] . ' left') . $tile('Retention', $P['att']['retention'] === null ? '—' : $P['att']['retention'] . '%', 'of those on the roll then')
 . (isset($S['attendance']) ? $tile('Punched in today', $S['attendance']['present'], isset($S['attendance']['leave']) ? $S['attendance']['leave'] . ' on leave' : '') : '')
 . (isset($S['statutory']) ? $tile('Statutory dues', count($S['statutory']['dues']), count(array_filter($S['statutory']['dues'], fn($x) => $x['late'])) . ' missed') : '') ?></div>
<div class="grid">
<div class="card"><h3>Headcount by month</h3><table><tr><th>Month</th><th>On the roll</th><th>Joined</th><th>Left</th></tr><?php foreach ($P['trend'] as $m => $r): ?><tr><td><?= h(date('M Y', strtotime($m . '-01'))) ?></td><td><?= $r['headcount'] ?></td><td><?= $r['joined'] ?></td><td><?= $r['left'] ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h3>Pending work by desk</h3><?= $table($P['desks'], 'Desk', 'Waiting') ?></div>
<div class="card"><h3>By branch</h3><?= $table($P['byBranch'], 'Branch', 'On the roll') ?></div>
<div class="card"><h3>By department</h3><?= $table($P['byDept'], 'Department', 'On the roll') ?></div>
<?php if (isset($S['leave'])): $L = $S['leave']; ?><div class="card"><h3>Leave</h3><p>With managers <b><?= $L['manager'] ?></b> · waiting for HR <b><?= $L['hr'] ?></b> · withdrawals <b><?= $L['withdraw'] ?></b> · on leave today <b><?= $L['today'] ?></b> · starting in 7 days <b><?= $L['next7'] ?></b></p>
<?= $table($L['byType'], 'Leave used since ' . date('j M Y', strtotime($L['from'])), 'Days') ?></div><?php endif; ?>
<?php if (isset($S['probation'])): $Pr = $S['probation']; ?><div class="card"><h3>Probation</h3><p>Ending in the next <?= $Pr['days'] ?> days <b><?= $Pr['ending'] ?></b> · reviews waiting <b><?= $Pr['reviews'] ?></b> · past their date <b><?= $Pr['late'] ?></b></p></div><?php endif; ?>
<?php if (isset($S['statutory'])): ?><div class="card"><h3>Statutory dues (next <?= $S['statutory']['days'] ?> days and missed)</h3><table><tr><th>Due</th><th>What</th><th>Scheme</th><th>Period</th></tr>
<?php foreach ($S['statutory']['dues'] as $x): ?><tr><td><?= $x['late'] ? '<b>Missed</b> ' : '' ?><?= h($x['date']) ?></td><td><?= h($x['what']) ?></td><td><?= h(strtoupper($x['scheme']) . ($x['state'] !== '' ? ' · ' . $x['state'] : '')) ?></td><td><?= h($x['period']) ?></td></tr><?php endforeach; ?>
<?php if (!$S['statutory']['dues']): ?><tr><td colspan="4" class="muted">Nothing due.</td></tr><?php endif; ?></table></div><?php endif; ?>
</div><p class="muted">Read only. Never a name, an employee code or a pay figure. Each part shows while its module is on.</p>
<?php endif; ui_foot();
