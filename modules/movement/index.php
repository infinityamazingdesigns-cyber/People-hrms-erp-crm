<?php
// Staff movement — gate desk (send out, back in), one entry (material answer, corrections, alerts), log + reports + CSV,
// settings (roles, limits, departments, reasons). A record only: no approval, nothing reaches attendance or pay.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/movement.php';
$u = module_require('movement');
$db = pdo(); $set = mv_settings(); $R = mv_rights($u); $scope = mv_scope($u); $uid = (int)$u['id'];
$isAdmin = has_role($u, 'SUPERADMIN');
if (!$R['view']) { http_response_code(403); ui_head('Staff movement', $u, 'movement'); echo '<div class="card">Your role has no access to the gate log.</div>'; ui_foot(); exit; }
$view = (string)($_GET['view'] ?? 'desk');
$bn = []; foreach (branches() as $b) if (vis_in($scope, (int)$b['id'])) $bn[(int)$b['id']] = $b['name'];
$allBn = []; foreach (branches(false) as $b) $allBn[(int)$b['id']] = $b['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $to = 'index.php';
    try {
        switch ($a) {
            case 'out':
                if (!$R['record']) throw new RuntimeException('Your role cannot record movement.');
                [$id, $n] = mv_send_out($db, $u, $_POST, mv_people($db, $scope, $set), $scope);
                flash($n > 1 ? "$n people sent out together." : 'Sent out.'); break;
            case 'in':
                if (!$R['record']) throw new RuntimeException('Your role cannot record movement.');
                flash(mv_mark_in($db, $u, $id, $scope, (string)($_POST['material'] ?? ''), (string)($_POST['note'] ?? '')) ? 'Back in.' : 'Already back.');
                if (!empty($_POST['back'])) $to = 'index.php?view=entry&id=' . $id; break;
            case 'gin':
                if (!$R['record']) throw new RuntimeException('Your role cannot record movement.');
                $k = 0; foreach (mv_group_open($db, $id, $scope) as $m) if ($m['material_out'] === '' && mv_mark_in($db, $u, (int)$m['id'], $scope)) $k++;
                flash("$k back in. People who took material are marked back one by one."); break;
            case 'correct':
                if (!$R['configure']) throw new RuntimeException('Only the settings roles correct times.');
                if (!mv_get($db, $id, $scope)) throw new RuntimeException('Entry not found.');
                mv_correct_time($db, $u, $id, (string)$_POST['field'], (string)$_POST['value'], (string)($_POST['reason'] ?? ''));
                flash('Time corrected; the original is kept.'); $to = 'index.php?view=entry&id=' . $id; break;
            case 'reason':
                if (!$R['configure']) throw new RuntimeException('Only the settings roles change reasons.');
                mv_reason_save($db, $u, $id, (string)$_POST['label'], (int)($_POST['sort'] ?? 0), !empty($_POST['active'])); flash('Saved.'); $to = 'index.php?view=settings'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = mv_settings_check($_POST, role_labels(), array_keys($allBn));
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['movement'] ?? ['roles' => module_roles('movement')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['movement', 1, implode(',', $st['roles'] ?: module_roles('movement')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'movement'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'in' && $id) $to = 'index.php?view=entry&id=' . $id; }
    redirect($to);
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : date('Y-m-01');
$tod = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : date('Y-m-d');
$fb = (int)($_GET['branch'] ?? 0); $fr = (int)($_GET['reason'] ?? 0); $fq = (string)($_GET['q'] ?? '');
if ($fb && !isset($bn[$fb])) $fb = 0;
if ($view === 'csv' && $R['report']) {
    header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="staff-movement-' . $from . '-' . $tod . '.csv"');
    $rows = mv_list($db, $scope, $from, $tod, $fb, $fr, $fq, 20000);
    audit($uid, 'movement_export', count($rows) . ' rows'); echo "\xEF\xBB\xBF" . mv_csv($rows, $allBn); exit;
}
try { mv_alert_tick($db); } catch (Throwable $e) {}

$reasons = mv_reasons($db);
$hid = fn(string $a, int $id) => csrf_field() . '<input type="hidden" name="a" value="' . h($a) . '"><input type="hidden" name="id" value="' . $id . '">';
$late = fn(array $m) => ($m['overdue'] ? ' <b style="color:#b42318">over ' . (int)$m['limit'] . ' min</b>' : '') . ($m['shift_end'] ? ' <b style="color:#b42318">past shift end</b>' : '');
ui_head('Staff movement', $u, 'movement'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'desk' ? '' : ' ghost' ?>" href="index.php">Gate</a>
<?php if ($R['report']): ?> <a class="btn sm<?= $view === 'log' ? '' : ' ghost' ?>" href="?view=log">Log &amp; reports</a><?php endif; ?>
<?php if ($R['configure']): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>
<?php if ($scope === []): ?><div class="card"><b>No branch ticked for your login.</b> Ask the Super Admin to tick your branch on Users.</div><?php endif; ?>

<?php if ($view === 'desk'): $open = mv_open($db, $scope, $fb); $back = mv_back_today($db, $scope, $fb); ?>
<form class="row"><select name="branch" onchange="this.form.submit()" aria-label="Branch"><option value="0">All my branches</option><?php foreach ($bn as $i => $n): ?><option value="<?= $i ?>"<?= $fb === $i ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></form>
<?php if ($R['record']): $people = mv_people($db, $scope, $set); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="out"><input type="hidden" name="token" value="<?= bin2hex(random_bytes(16)) ?>">
<h2>Going out</h2>
<label>Who (one or several; up to <?= MV_MAX_GROUP ?>) <input id="mvf" placeholder="Find by name, code or department" autocomplete="off"></label>
<div id="mvl" style="max-height:220px;overflow:auto;border:1px solid var(--line,#ddd);border-radius:8px;padding:6px">
<?php foreach ($people as $p): ?><label class="mvp" style="display:block" data-q="<?= h(mb_strtolower($p['name'] . ' ' . $p['code'] . ' ' . $p['dept'])) ?>"><input type="checkbox" name="codes[]" value="<?= h($p['code']) ?>"> <?= h($p['name']) ?> <span class="muted">· <?= h($p['code']) ?> · <?= h(implode(' · ', array_filter([(string)$p['dept'], $allBn[(int)$p['branch_id']] ?? '']))) ?></span></label><?php endforeach; ?>
<?php if (!$people): ?><p class="muted">Nobody on the roll in your branches<?= $set['departments'] !== '' ? ' and departments (' . h($set['departments']) . ')' : '' ?>.</p><?php endif; ?></div>
<div class="grid"><label>Reason <select name="reason_id"><option value="">— (optional)</option><?php foreach ($reasons as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['label']) ?></option><?php endforeach; ?></select></label>
<label>Other reason <input name="reason_other" maxlength="200"></label><label>Going to <input name="destination" maxlength="200"></label>
<label>Vehicle no. <input name="vehicle_no" maxlength="20"></label><label>Material taken out <input name="material_out" maxlength="300" placeholder="Asked again on return"></label></div>
<button class="btn">Out now</button></form>
<script>document.getElementById('mvf').addEventListener('input',function(){var q=this.value.toLowerCase().trim();document.querySelectorAll('#mvl .mvp').forEach(function(l){l.style.display=!q||l.dataset.q.indexOf(q)>=0?'block':'none';});});</script>
<?php endif; ?>
<div class="card"><h2>Out now <span class="muted"><?= count($open) ?></span></h2><?php if (!$open): ?><p class="muted">Nobody is out.</p><?php endif; ?>
<table><?php foreach ($open as $m): ?><tr><td><a href="?view=entry&id=<?= (int)$m['id'] ?>"><b><?= h($m['emp_name']) ?></b></a><div class="muted"><?= h(implode(' · ', array_filter([$m['emp_code'], $m['emp_dept'], $allBn[(int)$m['branch_id']] ?? '']))) ?></div></td>
<td><?= h($m['reason'] ?: $m['reason_other']) ?><div class="muted"><?= h($m['destination']) ?><?= $m['material_out'] !== '' ? ' · material: ' . h($m['material_out']) : '' ?></div></td>
<td>Out <?= h(substr((string)$m['out_at'], 11, 5)) ?> · <?= h(mv_dur((int)$m['mins'])) ?><?= $late($m) ?></td>
<td><?php if ($R['record']): if ($m['material_out'] !== ''): ?><a class="btn sm" href="?view=entry&id=<?= (int)$m['id'] ?>">Back in…</a><?php else: ?><form method="post"><?= $hid('in', (int)$m['id']) ?><button class="btn sm">Back in</button></form><?php endif; ?>
<?php if ($m['group_id']): ?><form method="post"><?= $hid('gin', (int)$m['id']) ?><button class="btn sm ghost">Whole group back</button></form><?php endif; endif; ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Back today <span class="muted"><?= count($back) ?></span></h2><?php if (!$back): ?><p class="muted">None yet.</p><?php endif; ?>
<table><?php foreach ($back as $m): ?><tr><td><a href="?view=entry&id=<?= (int)$m['id'] ?>"><?= h($m['emp_name']) ?></a></td><td><?= h(substr((string)$m['out_at'], 11, 5)) ?> – <?= h(substr((string)$m['in_at'], 11, 5)) ?></td><td><?= h(mv_dur($m['duration_min'] !== null ? (int)$m['duration_min'] : null)) ?></td><td><?= h(MV_MATERIAL_BACK[$m['material_back'] ?? ''] ?? '') ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($view === 'entry' && ($m = mv_get($db, (int)($_GET['id'] ?? 0), $scope))):
    $fl = $m['status'] === 'out' ? mv_flags($m, mv_limit($set, $m['branch_id'] !== null ? (int)$m['branch_id'] : null), mv_shift_end($db, (string)$m['emp_code'], substr((string)$m['out_at'], 0, 10)), time()) : null; ?>
<div class="card"><h2><?= h($m['emp_name']) ?> <span class="muted"><?= h($m['mv_no']) ?></span></h2>
<p><?= h(implode(' · ', array_filter([$m['emp_code'], $m['emp_dept'], $allBn[(int)$m['branch_id']] ?? '']))) ?></p>
<table><tr><th>Out</th><td><?= h((string)$m['out_at']) ?></td></tr><tr><th>Back</th><td><?= h((string)($m['in_at'] ?? 'Still out')) ?> <?= $m['duration_min'] !== null ? '· ' . h(mv_dur((int)$m['duration_min'])) : '' ?><?= $fl ? $late($fl) : '' ?></td></tr>
<tr><th>Reason</th><td><?= h(trim(($m['reason'] ?? '') . ' ' . $m['reason_other'])) ?></td></tr><tr><th>Going to</th><td><?= h($m['destination']) ?></td></tr><tr><th>Vehicle</th><td><?= h($m['vehicle_no']) ?></td></tr>
<tr><th>Material</th><td><?= h($m['material_out']) ?><?= $m['material_back'] ? ' — ' . h(MV_MATERIAL_BACK[$m['material_back']]) . ($m['material_note'] !== '' ? ': ' . h($m['material_note']) : '') : '' ?></td></tr></table>
<?php if ($m['status'] === 'out' && $R['record']): ?><form method="post"><?= $hid('in', (int)$m['id']) ?><input type="hidden" name="back" value="1">
<?php if ($m['material_out'] !== ''): ?><div class="row"><?php foreach (MV_MATERIAL_BACK as $k => $l): ?><label><input type="radio" name="material" value="<?= $k ?>" required> <?= h($l) ?></label><?php endforeach; ?></div><label>Note (needed unless all back) <input name="note" maxlength="300"></label><?php endif; ?>
<button class="btn">Back in now</button></form><?php endif; ?></div>
<?php if ($R['configure']): ?><form method="post" class="card"><?= $hid('correct', (int)$m['id']) ?><h3>Correct a time</h3><div class="row"><select name="field" aria-label="Which time"><option value="out_at">Out</option><?php if ($m['in_at']): ?><option value="in_at">Back</option><?php endif; ?></select>
<input type="datetime-local" name="value" required aria-label="Corrected time"><input name="reason" placeholder="Reason" required maxlength="300"><button class="btn sm">Correct</button></div></form><?php endif; ?>
<?php $cor = mv_corrections($db, (int)$m['id']); if ($cor): ?><div class="card"><h3>Corrections (original kept)</h3><table><?php foreach ($cor as $c): ?><tr><td><?= $c['field'] === 'out_at' ? 'Out' : 'Back' ?>: <?= h((string)$c['old_value']) ?> → <?= h((string)$c['new_value']) ?></td><td><?= h($c['reason']) ?></td><td class="muted"><?= h((string)$c['by_name']) ?> · <?= h((string)$c['at']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php $al = mv_alerts_for($db, (int)$m['id']); if ($al): ?><div class="card"><h3>Alerts</h3><table><?php foreach ($al as $x): ?><tr><td><?= h(MV_ALERT_KINDS[$x['kind']] ?? $x['kind']) ?></td><td><?= h((string)$x['name']) ?></td><td class="muted"><?= h($x['status'] === 'noted' ? 'Noted (no email)' : ucfirst($x['status'])) ?> · <?= h((string)$x['at']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<?php elseif ($view === 'log' && $R['report']): $rows = mv_list($db, $scope, $from, $tod, $fb, $fr, $fq); $sum = mv_summary($rows, $set); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="log"><input type="date" name="from" value="<?= h($from) ?>" aria-label="From"><input type="date" name="to" value="<?= h($tod) ?>" aria-label="To">
<select name="branch" aria-label="Branch"><option value="0">All my branches</option><?php foreach ($bn as $i => $n): ?><option value="<?= $i ?>"<?= $fb === $i ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
<select name="reason" aria-label="Reason"><option value="0">Any reason</option><?php foreach (mv_reasons($db, false) as $r): ?><option value="<?= (int)$r['id'] ?>"<?= $fr === (int)$r['id'] ? ' selected' : '' ?>><?= h($r['label']) ?></option><?php endforeach; ?></select>
<input name="q" value="<?= h($fq) ?>" placeholder="Name, code, place"><button class="btn sm">Show</button>
<a class="btn sm ghost" href="?<?= h(http_build_query(['view' => 'csv', 'from' => $from, 'to' => $tod, 'branch' => $fb, 'reason' => $fr, 'q' => $fq])) ?>">CSV</a></form>
<div class="card"><p><b><?= (int)$sum['total']['n'] ?></b> times out · <?= h(mv_dur((int)$sum['total']['min'])) ?> in all · <b><?= (int)$sum['total']['late'] ?></b> came back after the limit · <?= (int)$sum['total']['open'] ?> still out</p>
<div class="grid"><?php foreach (['person' => 'By person', 'reason' => 'By reason'] as $k => $t2): ?><div><h3><?= $t2 ?></h3><table><tr><th></th><th>Times</th><th>Time out</th><th>Late</th></tr><?php foreach (array_slice($sum[$k], 0, 15, true) as $key => $v): ?><tr><td><?= h((string)$key) ?></td><td><?= $v['n'] ?></td><td><?= h(mv_dur($v['min'])) ?></td><td><?= $v['late'] ?></td></tr><?php endforeach; ?></table></div><?php endforeach; ?></div></div>
<div class="card"><table><tr><th>No</th><th>Person</th><th>Out</th><th>Back</th><th>Reason</th><th>Material</th></tr><?php foreach ($rows as $m): ?>
<tr><td><a href="?view=entry&id=<?= (int)$m['id'] ?>"><?= h((string)$m['mv_no']) ?></a></td><td><?= h($m['emp_name']) ?> <span class="muted"><?= h($m['emp_code']) ?></span></td><td><?= h(substr((string)$m['out_at'], 0, 16)) ?></td><td><?= h($m['in_at'] ? substr((string)$m['in_at'], 11, 5) . ' · ' . mv_dur((int)$m['duration_min']) : 'Still out') ?></td><td><?= h($m['reason'] ?: $m['reason_other']) ?></td><td><?= h(MV_MATERIAL_BACK[$m['material_back'] ?? ''] ?? ($m['material_out'] !== '' ? 'Out' : '')) ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($view === 'settings' && $R['configure']): $roles = role_labels(); ?>
<div class="card"><h2>Reasons</h2><table><?php foreach (mv_reasons($db, false) as $r): ?><tr><td><form method="post" class="row"><?= $hid('reason', (int)$r['id']) ?><input name="label" value="<?= h($r['label']) ?>" aria-label="Label"><input type="number" name="sort" value="<?= (int)$r['sort'] ?>" style="width:70px" aria-label="Order"><label><input type="checkbox" name="active" value="1"<?= (int)$r['active'] ? ' checked' : '' ?>> In use</label><button class="btn sm ghost">Save</button></form></td></tr><?php endforeach; ?>
<tr><td><form method="post" class="row"><?= $hid('reason', 0) ?><input name="label" placeholder="New reason"><input type="number" name="sort" value="200" style="width:70px" aria-label="Order"><input type="hidden" name="active" value="1"><button class="btn sm">Add</button></form></td></tr></table></div>
<?php if ($isAdmin): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['record_roles' => 'Record out / back in', 'report_roles' => 'Log, reports and CSV', 'configure_roles' => 'Reasons and time corrections', 'alert_roles' => 'Late alerts (logins with the branch ticked)'] as $k => $l): ?>
<h3><?= h($l) ?></h3><div class="row" style="flex-wrap:wrap"><?php foreach ($roles as $code => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, (array)$set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<div class="grid"><label>Late after (minutes out) <input type="number" name="overdue_min" min="5" max="600" value="<?= (int)$set['overdue_min'] ?>"></label>
<?php foreach ($allBn as $i => $n): ?><label><?= h($n) ?> (blank = as above) <input type="number" name="branch_limits[<?= $i ?>]" min="5" max="600" value="<?= h((string)($set['branch_limits'][(string)$i] ?? '')) ?>"></label><?php endforeach; ?></div>
<label>Only these departments on the picker (comma list; blank = everyone) <input name="departments" value="<?= h($set['departments']) ?>"></label>
<label><input type="checkbox" name="alert_email" value="1"<?= $set['alert_email'] ? ' checked' : '' ?>> Email late alerts (Email must be on)</label>
<p class="muted">Reception and Production Head logins work only in the branches ticked for them on Users; with none ticked they see no branch.</p><button class="btn">Save</button></form><?php endif; ?>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
