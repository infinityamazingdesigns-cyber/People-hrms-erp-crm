<?php
// Performance (KRA) — my evaluation, my team, one evaluation (review), HR console, role sheets + holders, requests, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/pms.php';
$u = module_require('pms');
$db = pdo(); $set = pms_settings(); $uid = (int)$u['id']; $hr = pms_is_hr($u); $isAdmin = has_role($u, 'SUPERADMIN');
$me = strtoupper((string)($u['emp_code'] ?? ''));
$fy = pms_fy_start();
[$curKey] = pms_current();
$per = (string)($_GET['p'] ?? $curKey);
if (!preg_match('/^(\d{4}-\d{2}|FY\d{4}(-[QH]\d)?)$/', $per)) $per = $curKey;
$perLabel = pms_period_step($per, 0, $set['cycle'], $fy)[1];
$view = (string)($_GET['view'] ?? 'mine');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $p = (string)($_POST['p'] ?? $per);
    $to = 'index.php?view=' . urlencode($view) . '&p=' . urlencode($p) . ($id && in_array($view, ['eval', 'sheet'], true) ? '&id=' . $id : '');
    try {
        switch ($a) {
            case 'self': pms_self($db, $u, (int)$_POST['sheet'], $p, $_POST, !empty($_POST['submit'])); flash(!empty($_POST['submit']) ? 'Sent to your manager.' : 'Saved.'); break;
            case 'recall': pms_recall($db, $u, $id); flash('Taken back — you can change it.'); break;
            case 'review': pms_review($db, $u, $id, $_POST); flash('Review saved.'); break;
            case 'return': pms_return($db, $u, $id, (string)($_POST['note'] ?? '')); flash('Sent back.'); break;
            case 'close': pms_close($db, $u, $id, (string)($_POST['note'] ?? '')); flash('Closed.'); break;
            case 'reopen': pms_close($db, $u, $id, (string)($_POST['note'] ?? ''), true); flash('Opened again.'); break;
            case 'request': pms_request($db, $u, (string)($_POST['note'] ?? '')); flash('HR is asked to link a sheet.'); break;
            case 'closereq': if (!$hr) throw new RuntimeException('Only HR.'); $db->prepare("UPDATE pms_request SET status = 'closed', closed_by = ?, closed_at = NOW(), close_note = ? WHERE id = ?")->execute([$uid, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300), $id]); flash('Closed.'); break;
            case 'sheet':
                $kras = [];
                foreach ((array)($_POST['kn'] ?? []) as $i => $kn) {
                    $kp = [];
                    foreach ((array)($_POST['pn'][$i] ?? []) as $j => $pn) $kp[] = ['id' => $_POST['pid'][$i][$j] ?? '', 'name' => $pn, 'measure' => $_POST['pm'][$i][$j] ?? '', 'unit' => $_POST['pu'][$i][$j] ?? '', 'target' => $_POST['pt'][$i][$j] ?? '', 'polarity' => $_POST['pp'][$i][$j] ?? 'higher', 'w' => $_POST['pw'][$i][$j] ?? 1];
                    $kras[] = ['name' => $kn, 'weight' => $_POST['kw'][$i] ?? 0, 'kpis' => $kp];
                }
                $id = pms_sheet_save($db, $u, $id, ['kras' => $kras] + $_POST); flash('Sheet saved.'); $to = 'index.php?view=sheet&id=' . $id; break;
            case 'assign': pms_assign($db, $u, $id, (string)$_POST['code'], (int)($_POST['share'] ?? 100), (string)$_POST['from_ym']); flash('Linked.'); $to = 'index.php?view=sheet&id=' . $id; break;
            case 'unassign': pms_unassign($db, $u, (int)$_POST['aid']); flash('Unlinked; earlier evaluations stay.'); $to = 'index.php?view=sheet&id=' . $id; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = pms_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['pms'] ?? ['roles' => module_roles('pms')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['pms', 1, implode(',', $st['roles'] ?: module_roles('pms')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'pms'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

if ($view === 'csv' && $hr) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="pms-' . $per . '.csv"');
    $q = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"';
    echo "\xEF\xBB\xBF" . implode(',', array_map($q, ['Code', 'Name', 'Department', 'Sheet', 'Status', 'Self score', 'Manager score', 'Band'])) . "\r\n";
    foreach (pms_console($db, $u, $per) as $r) { $s = $r['eval']['mgr_score'] ?? null; echo implode(',', array_map($q, [$r['emp_code'], $r['name'], $r['department'], $r['title'], PMS_STATUS[$r['eval']['status'] ?? 'none'], $r['eval']['self_score'] ?? '', $s ?? '', $s !== null ? pms_band((float)$s, $set['bands']) : ''])) . "\r\n"; }
    audit($uid, 'pms_export', $per); exit;
}

$pnav = function (string $view) use ($per, $perLabel, $set, $fy): string {
    [$pk] = pms_period_step($per, -1, $set['cycle'], $fy); [$nk] = pms_period_step($per, 1, $set['cycle'], $fy);
    return '<div class="row"><a class="btn sm ghost" href="?view=' . $view . '&p=' . urlencode($pk) . '">‹</a> <b>' . h($perLabel) . '</b> <a class="btn sm ghost" href="?view=' . $view . '&p=' . urlencode($nk) . '">›</a></div>';
};
$tgt = fn(array $k) => ($k['polarity'] === 'lower' ? '≤ ' : ($k['polarity'] === 'zero' ? '' : '≥ ')) . (float)$k['target'] . ($k['unit'] !== '' ? ' ' . $k['unit'] : '');
$hidden = fn(string $a, int $id = 0) => csrf_field() . '<input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="p" value="' . h($per) . '">';
$team = pms_team($db, $u, $per);
$waitMgr = count(array_filter($team, fn($r) => $r['direct'] && $r['status'] === 'submitted'));
ui_head('Performance', $u, 'pms'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'mine' ? '' : ' ghost' ?>" href="?view=mine&p=<?= h(urlencode($per)) ?>">My evaluation</a>
<?php if ($team): ?> <a class="btn sm<?= $view === 'team' ? '' : ' ghost' ?>" href="?view=team&p=<?= h(urlencode($per)) ?>">My team<?= $waitMgr ? " ($waitMgr to review)" : '' ?></a><?php endif; ?>
<?php if ($hr): ?> <a class="btn sm<?= $view === 'console' ? '' : ' ghost' ?>" href="?view=console&p=<?= h(urlencode($per)) ?>">HR console</a> <a class="btn sm<?= $view === 'sheets' ? '' : ' ghost' ?>" href="?view=sheets">Role sheets</a> <a class="btn sm<?= $view === 'requests' ? '' : ' ghost' ?>" href="?view=requests">Requests</a><?php endif; ?>
<?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php
$kpiTable = function (array $kras, array $self, ?array $mgr, bool $edit, string $mode) use ($tgt, $set): void {
    $sc = pms_score($kras, $mode === 'mgr' && $mgr ? $mgr['a'] : $self['a'] ?? [], (int)$set['cap']); ?>
<table><tr><th>KRA / KPI</th><th>Target</th><th><?= $mode === 'mgr' ? 'Person said' : 'Actual' ?></th><?php if ($mode === 'mgr'): ?><th>Agreed actual</th><?php endif; ?><th>Achievement</th><th>Comment</th></tr>
<?php foreach ($kras as $i => $k): ?><tr><td colspan="<?= $mode === 'mgr' ? 6 : 5 ?>"><b><?= h($k['name']) ?></b> <span class="muted">weight <?= (int)$k['weight'] ?> · score <?= $sc['kras'][$i]['score'] ?? '—' ?></span></td></tr>
<?php foreach ($k['kpis'] as $x): $sa = $self['a'][$x['id']] ?? null; $ma = $mgr['a'][$x['id']] ?? null; ?><tr><td><?= h($x['name']) ?><div class="muted"><?= h($x['measure']) ?></div></td><td><?= h($tgt($x)) ?></td>
<?php if ($mode === 'mgr'): ?><td><?= $sa === null ? '—' : h((string)$sa) ?><?= ($self['c'][$x['id']] ?? '') !== '' ? '<div class="muted">' . h($self['c'][$x['id']]) . '</div>' : '' ?></td>
<td><?= $edit ? '<input name="v[' . h($x['id']) . ']" value="' . h((string)($ma ?? '')) . '" placeholder="' . h($sa === null ? '' : 'agree: ' . $sa) . '" inputmode="decimal" style="width:90px">' : h((string)($ma ?? '—')) ?></td>
<td><?= $sc['kras'][$i]['kpis'][$x['id']] ?? '—' ?>%</td><td><?= $edit ? '<input name="c[' . h($x['id']) . ']" value="' . h((string)($mgr['c'][$x['id']] ?? '')) . '">' : h((string)($mgr['c'][$x['id']] ?? '')) ?></td>
<?php else: ?><td><?= $edit ? '<input name="v[' . h($x['id']) . ']" value="' . h((string)($sa ?? '')) . '" inputmode="decimal" style="width:90px">' : h((string)($sa ?? '—')) ?></td><td><?= $sc['kras'][$i]['kpis'][$x['id']] ?? '—' ?>%</td>
<td><?= $edit ? '<input name="c[' . h($x['id']) . ']" value="' . h((string)($self['c'][$x['id']] ?? '')) . '">' : h((string)($self['c'][$x['id']] ?? '')) ?></td><?php endif; ?></tr><?php endforeach; endforeach; ?>
<tr><td colspan="<?= $mode === 'mgr' ? 6 : 5 ?>"><b>Score <?= $sc['total'] ?? '—' ?></b> · <?= h(pms_band($sc['total'], $set['bands'])) ?><?= $sc['covered'] && $sc['covered'] < 100 ? ' <span class="muted">(' . (int)$sc['covered'] . '% of the weight has actuals)</span>' : '' ?></td></tr></table>
<?php };

if ($view === 'mine'): ?>
<?= $pnav('mine') ?>
<?php if ($me === ''): ?><div class="card">Your login is not linked to an employee code — ask HR.</div><?php else:
    $hold = pms_holdings($db, $me);
    if (!$hold): ?><form method="post" class="card"><?= $hidden('request') ?><h2>No KRA sheet yet</h2><p>Ask HR to link the role sheet for your position.</p><input name="note" placeholder="Note for HR (optional)" maxlength="300"><button class="btn">Ask for my KRA sheet</button></form>
<?php endif;
    foreach ($hold as $hd): $sh = pms_sheet_get($db, (int)$hd['sheet_id']); $e = pms_eval_get($db, $me, (int)$hd['sheet_id'], $per);
        $st = $e['status'] ?? 'none'; $edit = in_array($st, ['none', 'draft', 'returned'], true); $kras = $e ? $e['kras'] : $sh['kras']; ?>
<form method="post" class="card"><?= $hidden('self', (int)($e['id'] ?? 0)) ?><input type="hidden" name="sheet" value="<?= (int)$hd['sheet_id'] ?>">
<h2><?= h($hd['title']) ?> <span class="muted"><?= (int)$hd['share'] ?>% · <?= h(PMS_STATUS[$st]) ?></span></h2>
<?php if ($st === 'returned'): ?><p><b>Sent back:</b> <?= h($e['return_note']) ?></p><?php endif; ?>
<?php $kpiTable($kras, $e['self_a'] ?? [], null, $edit, 'self'); ?>
<?php if ($edit): ?><label>Overall note <textarea name="self_note" rows="2"><?= h((string)($e['self_note'] ?? '')) ?></textarea></label><div class="row"><button class="btn ghost">Save</button> <button class="btn" name="submit" value="1">Submit to my manager</button></div>
<?php else: ?><?= $e['self_note'] ? '<p>' . h($e['self_note']) . '</p>' : '' ?>
<?php if (in_array($st, ['reviewed', 'closed'], true) && $e['mgr_note']): ?><h3>Manager's review · score <?= h((string)$e['mgr_score']) ?> · <?= h(pms_band($e['mgr_score'] !== null ? (float)$e['mgr_score'] : null, $set['bands'])) ?></h3><?php $kpiTable($kras, $e['self_a'], $e['mgr_a'], false, 'mgr'); ?><p><?= h($e['mgr_note']) ?></p><?= $e['hr_note'] ? '<p class="muted">HR: ' . h($e['hr_note']) . '</p>' : '' ?><?php endif; ?>
<?php endif; ?></form>
<?php if ($st === 'submitted'): ?><form method="post"><?= $hidden('recall', (int)$e['id']) ?><button class="btn sm ghost">Take it back to change</button></form><?php endif; ?>
<?php endforeach; endif;

elseif ($view === 'team'): ?>
<?= $pnav('team') ?>
<div class="card"><h2>My team · <?= h($perLabel) ?></h2><table><tr><th>Person</th><th>Sheet</th><th>Status</th><th>Self</th><th>Agreed</th><th></th></tr>
<?php foreach ($team as $r): ?><tr><td><?= h($r['name']) ?><?= $r['direct'] ? '' : ' <span class="muted">(indirect)</span>' ?></td><td><?= h($r['title']) ?></td><td><?= h(PMS_STATUS[$r['status'] ?? 'none']) ?></td><td><?= h((string)($r['self_score'] ?? '')) ?></td><td><?= h((string)($r['mgr_score'] ?? '')) ?></td>
<td><?= $r['eval_id'] && $r['status'] !== 'draft' ? '<a class="btn sm' . ($r['direct'] && $r['status'] === 'submitted' ? '' : ' ghost') . '" href="?view=eval&id=' . (int)$r['eval_id'] . '&p=' . h(urlencode($per)) . '">' . ($r['direct'] && $r['status'] === 'submitted' ? 'Review' : 'Open') . '</a>' : '' ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($view === 'eval'):
    $e = pms_eval_by_id($db, (int)($_GET['id'] ?? 0));
    $who = $e ? pms_role_on($db, $u, $e) : '';
    $canSee = $e && ($who !== '' || in_array($e['emp_code'], array_column($team, 'emp_code'), true));
    if (!$canSee || $e['status'] === 'draft'): ?><div class="card">Not found.</div><?php else: $emp = emp_by_code($db, $e['emp_code']); $edit = $e['status'] === 'submitted' && $who !== ''; ?>
<form method="post" class="card"><?= $hidden('review', (int)$e['id']) ?><h2><?= h((string)$emp['name']) ?> · <?= h($e['period']) ?> <span class="muted"><?= h(PMS_STATUS[$e['status']]) ?></span></h2>
<p class="muted">Self score <?= h((string)$e['self_score']) ?><?= $e['mgr_score'] !== null ? ' · agreed ' . h((string)$e['mgr_score']) . ' · ' . h(pms_band((float)$e['mgr_score'], $set['bands'])) : '' ?></p>
<?= $e['self_note'] ? '<p>Their note: ' . h($e['self_note']) . '</p>' : '' ?>
<?php $kpiTable($e['kras'], $e['self_a'], $e['mgr_a'] ?: null, $edit, 'mgr'); ?>
<?php if ($edit): ?><p class="muted">Leave an agreed actual blank to agree with what they said.</p><label>Overall comment * <textarea name="mgr_note" rows="3" required></textarea></label><button class="btn">Save review</button><?php else: ?><?= $e['mgr_note'] ? '<p>' . h($e['mgr_note']) . '</p>' : '' ?><?= $e['hr_note'] ? '<p class="muted">HR: ' . h($e['hr_note']) . '</p>' : '' ?><?php endif; ?></form>
<?php if ($who !== '' && !pms_step_problem($e['status'], 'return', $who)): ?><form method="post" class="card row"><?= $hidden('return', (int)$e['id']) ?><input name="note" placeholder="What to change" required><button class="btn sm ghost">Send back to the person</button></form><?php endif; ?>
<?php if ($who === 'hr' && $e['status'] === 'reviewed'): ?><form method="post" class="card row"><?= $hidden('close', (int)$e['id']) ?><input name="note" placeholder="HR note (optional)"><button class="btn sm">Close</button></form><?php endif; ?>
<?php if ($who === 'hr' && $e['status'] === 'closed'): ?><form method="post" class="card row"><?= $hidden('reopen', (int)$e['id']) ?><input name="note" placeholder="Why it is opened again" required><button class="btn sm ghost">Open again</button></form><?php endif; ?>
<?php endif;

elseif ($view === 'console' && $hr): $rows = pms_console($db, $u, $per); $cnt = array_count_values(array_map(fn($r) => $r['eval']['status'] ?? 'none', $rows)); ?>
<?= $pnav('console') ?>
<div class="grid"><?php foreach (PMS_STATUS as $k => $l): ?><div class="card"><b><?= (int)($cnt[$k] ?? 0) ?></b><div class="muted"><?= h($l) ?></div></div><?php endforeach; ?></div>
<div class="card"><div class="row"><h2>Everyone with a sheet · <?= h($perLabel) ?></h2> <a class="btn sm ghost" href="?view=csv&p=<?= h(urlencode($per)) ?>">Download CSV</a></div>
<table><tr><th>Person</th><th>Sheet</th><th>Status</th><th>Self</th><th>Agreed</th><th>Band</th><th></th></tr>
<?php foreach ($rows as $r): $x = $r['eval']; ?><tr><td><?= h($r['name']) ?><div class="muted"><?= h($r['department']) ?></div></td><td><?= h($r['title']) ?></td><td><?= h(PMS_STATUS[$x['status'] ?? 'none']) ?>
<?php foreach ($r['elsewhere'] as $op): ?><div><a class="muted" href="?view=console&p=<?= h(urlencode($op)) ?>">Waiting for <?= h($op) ?> ›</a></div><?php endforeach; ?></td>
<td><?= h((string)($x['self_score'] ?? '')) ?></td><td><?= h((string)($x['mgr_score'] ?? '')) ?></td><td><?= $x && $x['mgr_score'] !== null ? h(pms_band((float)$x['mgr_score'], $set['bands'])) : '' ?></td>
<td><?= $x && $x['status'] !== 'draft' ? '<a class="btn sm ghost" href="?view=eval&id=' . (int)$x['id'] . '&p=' . h(urlencode($per)) . '">Open</a>' : '' ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="muted">Nobody holds a sheet yet. Link sheets on Role sheets.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'sheets' && $hr): $sheets = $db->query('SELECT s.*, (SELECT COUNT(*) FROM pms_assign a WHERE a.sheet_id = s.id AND a.removed_at IS NULL) AS holders FROM pms_sheet s ORDER BY FIELD(s.status,\'active\',\'draft\',\'retired\'), s.title')->fetchAll(); ?>
<div class="card"><div class="row"><h2>Role sheets</h2> <a class="btn sm" href="?view=sheet&id=0">New sheet</a></div><table><tr><th>Role</th><th>Department</th><th>Version</th><th>Status</th><th>Holders</th></tr>
<?php foreach ($sheets as $s): ?><tr><td><a href="?view=sheet&id=<?= (int)$s['id'] ?>"><?= h($s['title']) ?></a><div class="muted"><?= h($s['code']) ?></div></td><td><?= h($s['department']) ?></td><td>v<?= (int)$s['version'] ?></td><td><?= h($s['status']) ?></td><td><?= (int)$s['holders'] ?></td></tr><?php endforeach; ?>
<?php if (!$sheets): ?><tr><td colspan="5" class="muted">No sheets yet.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'sheet' && $hr):
    $s = (int)($_GET['id'] ?? 0) ? pms_sheet_get($db, (int)$_GET['id']) : null;
    $s ??= ['id' => 0, 'code' => '', 'title' => '', 'department' => '', 'grade' => '', 'summary' => '', 'status' => 'draft', 'version' => 1, 'kras' => []];
    $nk = max(count($s['kras']) + 2, 4); $sum = array_sum(array_column($s['kras'], 'weight')); ?>
<form method="post" class="card"><?= $hidden('sheet', (int)$s['id']) ?><h2><?= $s['id'] ? h($s['title']) . ' <span class="muted">v' . (int)$s['version'] . '</span>' : 'New role sheet' ?></h2>
<div class="grid"><label>Title * <input name="title" value="<?= h($s['title']) ?>" required></label><label>Code <input name="code" value="<?= h($s['code']) ?>" placeholder="from the title"></label>
<label>Department <input name="department" value="<?= h($s['department']) ?>"></label><label>Grade <input name="grade" value="<?= h($s['grade']) ?>"></label>
<label>Status <select name="status"><?php foreach (['draft' => 'Draft', 'active' => 'Active (can be linked)', 'retired' => 'Retired'] as $k => $l): ?><option value="<?= $k ?>"<?= $s['status'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label></div>
<label>Role summary <textarea name="summary" rows="2"><?= h($s['summary']) ?></textarea></label>
<p class="muted">KRA weights must total 100 (now <?= $sum ?>). Inside a KRA, KPI weights are relative (1–10). An empty row is ignored. Changing the KRAs keeps the earlier version; evaluations keep the sheet as it was.</p>
<?php for ($i = 0; $i < $nk; $i++): $k = $s['kras'][$i] ?? ['name' => '', 'weight' => '', 'kpis' => []]; $np = max(count($k['kpis']) + 1, 3); ?>
<details class="card"<?= $k['name'] !== '' || $i === 0 ? ' open' : '' ?>><summary><b><?= $k['name'] !== '' ? h($k['name']) : 'KRA ' . ($i + 1) ?></b> <?= $k['weight'] !== '' ? '· ' . (int)$k['weight'] : '' ?></summary>
<div class="grid"><input name="kn[<?= $i ?>]" value="<?= h($k['name']) ?>" placeholder="KRA name"><input name="kw[<?= $i ?>]" value="<?= h((string)$k['weight']) ?>" placeholder="Weight" inputmode="numeric"></div>
<table><tr><th>KPI</th><th>How it is measured</th><th>Unit</th><th>Target</th><th>Polarity</th><th>Weight</th></tr>
<?php for ($j = 0; $j < $np; $j++): $x = $k['kpis'][$j] ?? ['id' => '', 'name' => '', 'measure' => '', 'unit' => '', 'target' => '', 'polarity' => 'higher', 'w' => 1]; ?>
<tr><td><input type="hidden" name="pid[<?= $i ?>][<?= $j ?>]" value="<?= h($x['id']) ?>"><input name="pn[<?= $i ?>][<?= $j ?>]" value="<?= h($x['name']) ?>"></td><td><input name="pm[<?= $i ?>][<?= $j ?>]" value="<?= h($x['measure']) ?>"></td>
<td><input name="pu[<?= $i ?>][<?= $j ?>]" value="<?= h($x['unit']) ?>" style="width:60px"></td><td><input name="pt[<?= $i ?>][<?= $j ?>]" value="<?= h((string)$x['target']) ?>" style="width:80px"></td>
<td><select name="pp[<?= $i ?>][<?= $j ?>]"><?php foreach (PMS_POLARITY as $pk => $pl): ?><option value="<?= $pk ?>"<?= $x['polarity'] === $pk ? ' selected' : '' ?>><?= h($pl) ?></option><?php endforeach; ?></select></td><td><input name="pw[<?= $i ?>][<?= $j ?>]" value="<?= (int)$x['w'] ?>" style="width:50px"></td></tr><?php endfor; ?></table></details>
<?php endfor; ?><button class="btn">Save sheet</button></form>
<?php if ($s['id']): $hold = $db->prepare('SELECT a.*, e.name FROM pms_assign a LEFT JOIN employee e ON e.code = a.emp_code WHERE a.sheet_id = ? AND a.removed_at IS NULL ORDER BY e.name'); $hold->execute([(int)$s['id']]);
    $roll = $db->query('SELECT code, name FROM employee WHERE active = 1 AND (last_day IS NULL OR last_day >= CURDATE()) ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR); ?>
<div class="card"><h3>Holders</h3><table><?php foreach ($hold as $hd): ?><tr><td><?= h((string)$hd['name']) ?> <span class="muted"><?= h($hd['emp_code']) ?></span></td><td><?= (int)$hd['share'] ?>% from <?= h($hd['from_ym']) ?></td>
<td><form method="post"><?= $hidden('unassign', (int)$s['id']) ?><input type="hidden" name="aid" value="<?= (int)$hd['id'] ?>"><button class="btn sm ghost">Unlink</button></form></td></tr><?php endforeach; ?></table>
<?php if ($s['status'] === 'active'): ?><form method="post" class="row"><?= $hidden('assign', (int)$s['id']) ?><select name="code" required><option value="">Person on the roll</option><?php foreach ($roll as $c => $n): ?><option value="<?= h($c) ?>"><?= h($n . ' · ' . $c) ?></option><?php endforeach; ?></select>
<input name="share" value="100" style="width:60px" inputmode="numeric">% <input type="month" name="from_ym" value="<?= date('Y-m') ?>" required><button class="btn sm">Link</button></form><?php else: ?><p class="muted">Make the sheet active to link people.</p><?php endif; ?></div>
<?php endif;

elseif ($view === 'requests' && $hr): $reqs = $db->query("SELECT r.*, e.name FROM pms_request r LEFT JOIN employee e ON e.code = r.emp_code WHERE r.status = 'open' ORDER BY r.id")->fetchAll(); ?>
<div class="card"><h2>Asked for a KRA sheet</h2><table><?php foreach ($reqs as $r): ?><tr><td><?= h((string)$r['name']) ?> <span class="muted"><?= h($r['emp_code']) ?></span><div class="muted"><?= h($r['note']) ?></div></td><td><?= h(substr((string)$r['created_at'], 0, 10)) ?></td>
<td><form method="post" class="row"><?= $hidden('closereq', (int)$r['id']) ?><input name="note" placeholder="Note"><button class="btn sm ghost">Close</button></form></td></tr><?php endforeach; ?>
<?php if (!$reqs): ?><tr><td class="muted">None. Linking a sheet closes the person's request.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'settings' && $isAdmin): $b = $set['bands']; $b[] = ['min' => '', 'label' => '']; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<h3>HR roles (console, role sheets, closing)</h3><div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $c => $n): ?><label><input type="checkbox" name="hr_roles[]" value="<?= h($c) ?>"<?= in_array($c, $set['hr_roles'], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div>
<div class="grid"><label>Review cycle <select name="cycle"><?php foreach (PMS_CYCLES as $k => $l): ?><option value="<?= $k ?>"<?= $set['cycle'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
<label>Highest achievement counted (%) <input type="number" name="cap" min="100" max="200" value="<?= (int)$set['cap'] ?>"></label></div>
<label><input type="checkbox" name="hr_step" value="1"<?= $set['hr_step'] ? ' checked' : '' ?>> HR closes each review (else the manager's review closes it)</label><br>
<label><input type="checkbox" name="notify" value="1"<?= $set['notify'] ? ' checked' : '' ?>> Email the next person (Email must be on)</label>
<h3>Bands</h3><table><tr><th>From score</th><th>Label</th></tr><?php foreach ($b as $x): ?><tr><td><input name="b_min[]" value="<?= h((string)$x['min']) ?>" style="width:70px"></td><td><input name="b_label[]" value="<?= h((string)$x['label']) ?>"></td></tr><?php endforeach; ?></table>
<p class="muted">The year starts in the month set on Admin → Organisation.</p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
