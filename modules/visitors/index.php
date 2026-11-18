<?php
// Visitors — desk, new visitor (one or a group, now or expected), one visit, register + export, reports, removal of
// old details, settings. Visitors for me (host answers) is mine.php; the printed pass is pass.php.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/visitors.php';
$u = module_require('visitors');
$db = pdo(); $set = vis_settings(); $R = vis_rights($u); $scope = vis_scope($u); $uid = (int)$u['id'];
$isAdmin = has_role($u, 'SUPERADMIN');
$view = (string)($_GET['view'] ?? 'desk');
$bn = []; foreach (branches() as $b) if (vis_in($scope, (int)$b['id'])) $bn[(int)$b['id']] = $b['name'];
$allBn = []; foreach (branches(false) as $b) $allBn[(int)$b['id']] = $b['name'];

if ($view === 'lookup') {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    if (!$R['desk']) { echo '{"ok":false}'; exit; }
    try { $r = vis_lookup($db, $u, (string)($_GET['m'] ?? ''), $scope); echo json_encode(['ok' => true, 'v' => $r]); }
    catch (RuntimeException $e) { echo json_encode(['ok' => false, 'msg' => $e->getMessage()]); }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $to = 'index.php' . ($id ? '?view=visit&id=' . $id : '');
    try {
        switch ($a) {
            case 'new':
                $others = [];
                foreach ((array)($_POST['g_name'] ?? []) as $i => $n) $others[] = ['name' => $n, 'mobile' => $_POST['g_mobile'][$i] ?? '', 'email' => $_POST['g_email'][$i] ?? '', 'company' => $_POST['g_company'][$i] ?? $_POST['company'] ?? '', 'designation' => $_POST['g_designation'][$i] ?? ''];
                $others = array_filter($others, fn($o) => trim((string)$o['name']) !== '');
                if ($others) { [$id, $n] = vis_register_group($db, $u, $_POST, $others, $scope); flash("$n visitors registered together."); }
                else { $id = vis_register($db, $u, $_POST, $scope); flash(($_POST['when'] ?? '') === 'later' ? 'Expected visit saved.' : 'Registered.'); }
                $to = 'index.php?view=visit&id=' . $id; break;
            case 'answer': vis_set_approval($db, $u, $id, (string)$_POST['ans'], (string)($_POST['note'] ?? ''), $scope); flash('Answer recorded.'); break;
            case 'in': flash(vis_check_in($db, $u, $id, $scope) ? 'Checked in.' : 'Already checked in.'); break;
            case 'out': flash(vis_check_out($db, $u, $id, $scope) ? 'Checked out.' : 'Already checked out.'); break;
            case 'gin': case 'gout': $n = vis_group_step($db, $u, (int)$_POST['group'], $a === 'gin' ? 'in' : 'out', $scope); flash("$n " . ($a === 'gin' ? 'checked in.' : 'checked out.')); break;
            case 'arrive': flash(vis_arrive($db, $u, $id, $scope, !empty($_POST['now'])) ? 'Arrival recorded.' : 'Already recorded.'); break;
            case 'noshow': vis_no_show($db, $u, $id, (string)($_POST['note'] ?? ''), $scope); flash('Marked as not come.'); break;
            case 'cancel': vis_cancel($db, $u, $id, (string)($_POST['reason'] ?? ''), $scope); flash('Marked as left without entering.'); break;
            case 'correct': vis_correct_time($db, $u, $id, (string)$_POST['field'], (string)$_POST['value'], (string)($_POST['reason'] ?? ''), $scope); flash('Time corrected; the original is kept.'); break;
            case 'edit': $ch = vis_edit_details($db, $u, $id, $_POST, $scope); flash($ch ? 'Saved; earlier values kept.' : 'Nothing changed.'); break;
            case 'reauth': if (!vis_reauth($db, $u, (string)($_POST['password'] ?? ''))) throw new RuntimeException('That password is not right.'); flash('Confirmed for 10 minutes.'); $to = (string)($_POST['next'] ?? 'index.php'); if (!preg_match('/^index\.php\?view=(register|retention)[A-Za-z0-9=&_%-]*$/', $to)) $to = 'index.php'; break;
            case 'purge': if (!vis_reauth_fresh()) throw new RuntimeException('Confirm your password first.'); $n = vis_retention_purge($db, $u, (int)$set['keep_months']); flash("Details of $n visitors removed."); $to = 'index.php?view=retention'; break;
            case 'cat': vis_category_save($db, $u, $id, (string)$_POST['label'], (int)($_POST['sort'] ?? 0), !empty($_POST['active'])); flash('Saved.'); $to = 'index.php?view=settings'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = vis_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['visitors'] ?? ['roles' => module_roles('visitors')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['visitors', 1, implode(',', $st['roles'] ?: module_roles('visitors')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'visitors'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'new') $to = 'index.php?view=new'; }
    redirect($to);
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : date('Y-m-01');
$tod = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : date('Y-m-d');
$fb = (int)($_GET['branch'] ?? 0); $fc = (int)($_GET['cat'] ?? 0); $fq = (string)($_GET['q'] ?? '');
if (($view === 'csv' || $view === 'backup') && ($view === 'csv' ? $R['report'] : $R['purge'])) {
    if (!vis_reauth_fresh()) redirect('index.php?view=confirm&next=' . urlencode($view === 'csv' ? 'index.php?view=register&from=' . $from . '&to=' . $tod . '&branch=' . $fb . '&cat=' . $fc : 'index.php?view=retention'));
    header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store');
    if ($view === 'csv') { header('Content-Disposition: attachment; filename="visits-' . $from . '-' . $tod . '.csv"'); audit($uid, 'visit_export', $from . '..' . $tod); echo "\xEF\xBB\xBF" . vis_csv(vis_list($db, $scope, $from, $tod, $fb, $fc, $fq), $allBn, $R['configure']); }
    else { header('Content-Disposition: attachment; filename="visitor-backup-' . date('Y-m-d') . '.csv"'); echo "\xEF\xBB\xBF" . vis_retention_backup($db, $u, (int)$set['keep_months']); }
    exit;
}

$cats = vis_categories($db);
$sel = fn(array $o, string $n, string $c, string $x = '') => '<select name="' . $n . '"' . $x . '>' . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($o), $o)) . '</select>';
$hid = fn(string $a, int $id) => csrf_field() . '<input type="hidden" name="a" value="' . h($a) . '"><input type="hidden" name="id" value="' . $id . '">';
$st = fn(array $v) => h(VIS_STATUS[$v['status']] ?? $v['status']) . ($v['status'] === 'waiting' ? ' · ' . h(VIS_APPROVAL[$v['approval']] ?? '') : '');
ui_head('Visitors', $u, 'visitors'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'desk' ? '' : ' ghost' ?>" href="index.php">Desk</a>
<?php if ($R['desk']): ?> <a class="btn sm<?= $view === 'new' ? '' : ' ghost' ?>" href="?view=new">New visitor</a><?php endif; ?>
<?php if ($R['report']): ?> <a class="btn sm<?= $view === 'register' ? '' : ' ghost' ?>" href="?view=register">Register &amp; reports</a><?php endif; ?>
<?php if ($R['purge']): ?> <a class="btn sm<?= $view === 'retention' ? '' : ' ghost' ?>" href="?view=retention">Old details</a><?php endif; ?>
<?php if ($R['configure']): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>
<?php if ($scope === []): ?><div class="card"><b>No branch ticked for your login.</b> Ask the Super Admin to tick your branch on Users.</div><?php endif; ?>

<?php if ($view === 'desk'): $rows = vis_desk($db, $scope, $fb); $now = time();
    $groups = []; foreach ($rows as $r) if ($r['group_id']) $groups[(int)$r['group_id']][] = $r; ?>
<form class="row"><select name="branch" onchange="this.form.submit()"><option value="0">All my branches</option><?php foreach ($bn as $i => $n): ?><option value="<?= $i ?>"<?= $fb === $i ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></form>
<?php foreach (['waiting' => 'At reception', 'inside' => 'Inside', 'expected' => 'Expected (next 7 days)', 'done' => 'Earlier today'] as $grp => $title):
    $list = array_filter($rows, fn($r) => $grp === 'done' ? in_array($r['status'], ['out', 'cancelled'], true) : $r['status'] === $grp); ?>
<div class="card"><h2><?= h($title) ?> <span class="muted"><?= count($list) ?></span></h2>
<?php if (!$list): ?><p class="muted">None.</p><?php endif; ?>
<table><?php foreach ($list as $r): ?><tr><td><a href="?view=visit&id=<?= (int)$r['id'] ?>"><b><?= h($r['name']) ?></b></a><div class="muted"><?= h(implode(' · ', array_filter([$r['company'], $r['category'], $r['group_id'] ? 'group of ' . count($groups[(int)$r['group_id']] ?? []) : '']))) ?></div></td>
<td>Meeting <?= h($r['host_name']) ?><div class="muted"><?= h($allBn[(int)$r['branch_id']] ?? '') ?></div></td>
<td><?= $r['status'] === 'expected' ? h(date('D j M', strtotime((string)$r['exp_on'])) . ($r['exp_time'] ? ' ' . substr((string)$r['exp_time'], 0, 5) : '')) : ($r['check_in_at'] ? 'In ' . h(substr((string)$r['check_in_at'], 11, 5)) : '') ?><?= vis_overdue($r, (int)$set['overdue_hours'], $now) ? ' <b style="color:#b42318">over ' . (int)$set['overdue_hours'] . ' h</b>' : '' ?></td>
<td><?= $st($r) ?></td><td><?php if ($R['desk']): ?>
<?php if ($r['status'] === 'waiting' && !vis_checkin_problem($r)): ?><form method="post"><?= $hid('in', (int)$r['id']) ?><button class="btn sm">Check in</button></form><?php endif; ?>
<?php if ($r['status'] === 'inside'): ?><form method="post"><?= $hid('out', (int)$r['id']) ?><button class="btn sm ghost">Check out</button></form><?php endif; ?>
<?php if ($r['status'] === 'expected'): ?><form method="post"><?= $hid('arrive', (int)$r['id']) ?><input type="hidden" name="now" value="1"><button class="btn sm">Arrived</button></form><?php endif; ?>
<?php endif; ?></td></tr><?php endforeach; ?></table></div>
<?php endforeach; ?>

<?php elseif ($view === 'new' && $R['desk']): ?>
<form method="post" class="card" id="nv"><?= csrf_field() ?><input type="hidden" name="a" value="new"><input type="hidden" name="token" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="visitor_id" id="vid" value="">
<h2>New visitor</h2>
<div class="row"><label><input type="radio" name="when" value="now" checked> Here now</label> <label><input type="radio" name="when" value="later"> Expected later</label>
<span id="later" style="display:none"><input type="date" name="exp_on" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+' . VIS_EXPECT_DAYS . ' days')) ?>"> <input type="time" name="exp_time"></span></div>
<div class="grid"><label>Mobile <input name="mobile" id="mob" inputmode="tel"></label><span id="lk" class="muted"></span>
<label>Name * <input name="name" required maxlength="120"></label><label>Company <input name="company"></label><label>Designation <input name="designation"></label>
<label>Email <input type="email" name="email"></label><label>Address <input name="address"></label></div>
<details><summary>More mobiles / emails</summary><div class="grid"><?php for ($i = 0; $i < 2; $i++): ?><input name="mobiles[]" placeholder="Another mobile"><input type="email" name="emails[]" placeholder="Another email"><?php endfor; ?></div></details>
<h3>The visit</h3>
<div class="grid"><label>Branch * <?= $sel(['' => '—'] + $bn, 'branch_id', count($bn) === 1 ? (string)array_key_first($bn) : '', ' required') ?></label>
<label>Category * <?= $sel(['' => '—'] + array_column($cats, 'label', 'id'), 'category_id', '', ' required') ?></label>
<label>Meeting * <input name="host_name" id="host" list="hosts" required placeholder="Type a name or department"></label>
<input type="hidden" name="host_user_id" id="hostu"><input type="hidden" name="host_dept" id="hostd"><label>Purpose <input name="purpose" maxlength="300"></label></div>
<datalist id="hosts"><?php foreach (vis_hosts($db, $scope) as $hh): ?><option value="<?= h($hh['name']) ?>" data-u="<?= (int)$hh['user_id'] ?>" data-d="<?= h($hh['department']) ?>"><?= h($hh['department']) ?></option><?php endforeach; ?></datalist>
<div class="row"><label><input type="radio" name="approval" value="pending" checked> Ask the host first</label> <label><input type="radio" name="approval" value="not_needed"> No answer needed</label> <label><input type="checkbox" name="check_in_now" value="1"> Check in now (when no answer is needed)</label></div>
<label>Remarks <input name="remarks" maxlength="500"></label>
<details><summary>People who came together</summary><p class="muted">Each gets their own visit; saved all together or not at all.</p>
<table id="grp"><tr><th>Name</th><th>Mobile</th><th>Email</th><th>Designation</th></tr><?php for ($i = 0; $i < 4; $i++): ?><tr><td><input name="g_name[]"></td><td><input name="g_mobile[]"></td><td><input type="email" name="g_email[]"></td><td><input name="g_designation[]"></td></tr><?php endfor; ?></table>
<button type="button" class="btn sm ghost" id="addrow">Add a row</button></details>
<button class="btn">Save</button></form>
<script>
(function () {
  var f = document.getElementById('nv'), lat = document.getElementById('later');
  f.querySelectorAll('[name=when]').forEach(function (r) { r.addEventListener('change', function () { lat.style.display = f.querySelector('[name=when]:checked').value === 'later' ? '' : 'none'; }); });
  var host = document.getElementById('host');
  host.addEventListener('change', function () { var o = Array.prototype.find.call(document.querySelectorAll('#hosts option'), function (x) { return x.value === host.value; });
    document.getElementById('hostu').value = o ? o.getAttribute('data-u') || '' : ''; document.getElementById('hostd').value = o ? o.getAttribute('data-d') || '' : ''; });
  var mob = document.getElementById('mob'), lk = document.getElementById('lk'), t;
  mob.addEventListener('input', function () { clearTimeout(t); var m = mob.value.replace(/\D/g, ''); if (m.length < 10) { lk.textContent = ''; document.getElementById('vid').value = ''; return; }
    t = setTimeout(function () { fetch('index.php?view=lookup&m=' + encodeURIComponent(mob.value), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { lk.textContent = j.msg || ''; return; } if (!j.v) { lk.textContent = 'New visitor.'; document.getElementById('vid').value = ''; return; }
      document.getElementById('vid').value = j.v.id; lk.textContent = 'Visited ' + j.v.visits + ' time(s) before.' + (j.v.full ? '' : ' Only the name is shown — they have not visited your branches.');
      ['name', 'company', 'designation', 'email', 'address'].forEach(function (k) { var el = f.querySelector('[name=' + k + ']'); if (el && !el.value && j.v[k]) el.value = j.v[k]; }); }); }, 400); });
  document.getElementById('addrow').addEventListener('click', function () { var tb = document.getElementById('grp'); if (tb.rows.length > <?= VIS_MAX_GROUP ?>) return; tb.appendChild(tb.rows[1].cloneNode(true)); tb.rows[tb.rows.length - 1].querySelectorAll('input').forEach(function (i) { i.value = ''; }); });
})();
</script>

<?php elseif ($view === 'visit'):
    $v = vis_get($db, (int)($_GET['id'] ?? 0), $scope);
    if (!$v): ?><div class="card">Visit not found.</div><?php else:
    $ap = $db->prepare('SELECT a.*, u.name AS who FROM visit_approval a LEFT JOIN users u ON u.id = a.by_user WHERE a.visit_id = ? ORDER BY a.id'); $ap->execute([(int)$v['id']]);
    $co = $db->prepare('SELECT c.*, u.name AS who FROM visit_correction c LEFT JOIN users u ON u.id = c.by_user WHERE c.visit_id = ? ORDER BY c.id'); $co->execute([(int)$v['id']]);
    $ed = $db->prepare('SELECT e.*, u.name AS who FROM visitor_edit e LEFT JOIN users u ON u.id = e.by_user WHERE e.visitor_id = ? ORDER BY e.id'); $ed->execute([(int)$v['visitor_id']]); $ed = $ed->fetchAll();
    $cn = $db->prepare('SELECT kind, value FROM visitor_contact WHERE visitor_id = ?'); $cn->execute([(int)$v['visitor_id']]); $cn = $cn->fetchAll();
    $grp = $v['group_id'] ? vis_group($db, (int)$v['group_id'], $scope) : []; ?>
<div class="card"><h2><?= h($v['name']) ?> <span class="muted"><?= h((string)$v['visit_no']) ?></span></h2>
<p><?= h(implode(' · ', array_filter([$v['designation'], $v['company'], $v['category']]))) ?></p>
<p>Meeting <b><?= h($v['host_name']) ?></b><?= $v['host_dept'] !== '' ? ' (' . h($v['host_dept']) . ')' : '' ?> at <?= h($allBn[(int)$v['branch_id']] ?? '') ?><?= $v['purpose'] !== '' ? ' — ' . h($v['purpose']) : '' ?></p>
<p><b><?= $st($v) ?></b><?= $v['check_in_at'] ? ' · in ' . h((string)$v['check_in_at']) : '' ?><?= $v['check_out_at'] ? ' · out ' . h((string)$v['check_out_at']) . ' (' . (int)$v['duration_min'] . ' min)' : '' ?><?= $v['status'] === 'expected' ? ' · ' . h((string)$v['exp_on'] . ' ' . substr((string)$v['exp_time'], 0, 5)) : '' ?></p>
<?php if ($R['configure'] && $v['mobile']): ?><p class="muted"><?= h(implode(' · ', array_filter([$v['mobile'], $v['email'], ...array_column($cn, 'value')]))) ?></p><?php elseif ($v['mobile']): ?><p class="muted">Mobile <?= h(vis_mask($v['mobile'])) ?></p><?php endif; ?>
<?php if ($v['remarks'] !== ''): ?><p class="muted"><?= h($v['remarks']) ?></p><?php endif; ?>
<div class="row" style="flex-wrap:wrap"><a class="btn sm ghost" href="pass.php?id=<?= (int)$v['id'] ?>" target="_blank">Print pass</a>
<?php if ($R['desk']): ?>
<?php if ($v['status'] === 'waiting'): ?><form method="post" class="row"><?= $hid('answer', (int)$v['id']) ?><?= $sel(['approved' => 'Host said yes', 'rejected' => 'Host declined', 'no_answer' => 'No answer', 'pending' => 'Still waiting'], 'ans', 'approved') ?><input name="note" placeholder="How (phone, in person …)"><button class="btn sm">Record answer</button></form>
<?php if (!vis_checkin_problem($v)): ?><form method="post"><?= $hid('in', (int)$v['id']) ?><button class="btn sm">Check in</button></form><?php endif; ?>
<form method="post" class="row"><?= $hid('cancel', (int)$v['id']) ?><input name="reason" placeholder="Why they left"><button class="btn sm ghost">Left without entering</button></form><?php endif; ?>
<?php if ($v['status'] === 'inside'): ?><form method="post"><?= $hid('out', (int)$v['id']) ?><button class="btn sm">Check out</button></form><?php endif; ?>
<?php if ($v['status'] === 'expected'): ?><form method="post"><?= $hid('arrive', (int)$v['id']) ?><label><input type="checkbox" name="now" value="1" checked> Check in at once when no answer is needed</label> <button class="btn sm">Arrived</button></form>
<form method="post" class="row"><?= $hid('noshow', (int)$v['id']) ?><input name="note" placeholder="Note"><button class="btn sm ghost">Did not come</button></form><?php endif; ?>
<?php if (count($grp) > 1): ?><form method="post"><?= $hid('gin', (int)$v['id']) ?><input type="hidden" name="group" value="<?= (int)$v['group_id'] ?>"><button class="btn sm ghost">Check in the whole group</button></form><form method="post"><?= $hid('gout', (int)$v['id']) ?><input type="hidden" name="group" value="<?= (int)$v['group_id'] ?>"><button class="btn sm ghost">Check out the whole group</button></form><?php endif; ?>
<?php endif; ?></div></div>
<?php if (count($grp) > 1): ?><div class="card"><h3>Came together</h3><?php foreach ($grp as $g): ?><div><a href="?view=visit&id=<?= (int)$g['id'] ?>"><?= h($g['name']) ?></a> <span class="muted"><?= h(VIS_STATUS[$g['status']]) ?></span></div><?php endforeach; ?></div><?php endif; ?>
<div class="card"><h3>Host answers</h3><?php foreach ($ap as $a): ?><div class="muted"><?= h((string)$a['at']) ?> · <?= h(VIS_APPROVAL[$a['approval']] ?? $a['approval']) ?> · <?= h((string)$a['who']) ?><?= $a['note'] !== '' ? ' — ' . h($a['note']) : '' ?></div><?php endforeach; ?></div>
<?php if ($R['configure'] && $v['purged_at'] === null): ?>
<details class="card"><summary>Edit details (earlier values are kept)</summary><form method="post"><?= $hid('edit', (int)$v['id']) ?><div class="grid"><?php foreach (VIS_EDIT_FIELDS as $k => $l): ?><label><?= h($l) ?> <input name="<?= $k ?>" value="<?= h((string)$v[$k]) ?>"></label><?php endforeach; ?></div><input name="reason" placeholder="Reason" required><button class="btn sm">Save</button></form></details>
<details class="card"><summary>Correct a time (the original is kept)</summary><form method="post" class="row"><?= $hid('correct', (int)$v['id']) ?><?= $sel(['check_in_at' => 'Check-in', 'check_out_at' => 'Check-out'], 'field', 'check_in_at') ?><input type="datetime-local" name="value" required><input name="reason" placeholder="Reason" required><button class="btn sm">Correct</button></form></details>
<?php endif; ?>
<?php $cl = $co->fetchAll(); if ($cl || $ed): ?><div class="card"><h3>Changes</h3><?php foreach ($cl as $c): ?><div class="muted"><?= h((string)$c['at']) ?> · <?= h((string)$c['who']) ?> · <?= h($c['field'] === 'check_in_at' ? 'Check-in' : 'Check-out') ?> <?= h((string)$c['old_value']) ?> → <?= h((string)$c['new_value']) ?> — <?= h($c['reason']) ?></div><?php endforeach; ?>
<?php foreach ($ed as $c): ?><div class="muted"><?= h((string)$c['at']) ?> · <?= h((string)$c['who']) ?> · <?= h(VIS_EDIT_FIELDS[$c['field']] ?? $c['field']) ?> was “<?= h((string)$c['old_value']) ?>” — <?= h($c['reason']) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php endif;

elseif ($view === 'register' && $R['report']): $rows = vis_list($db, $scope, $from, $tod, $fb, $fc, $fq); $rep = vis_report($rows); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="register"><input type="date" name="from" value="<?= h($from) ?>"><input type="date" name="to" value="<?= h($tod) ?>">
<select name="branch"><option value="0">All my branches</option><?php foreach ($bn as $i => $n): ?><option value="<?= $i ?>"<?= $fb === $i ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
<?= $sel(['0' => 'All categories'] + array_column($cats, 'label', 'id'), 'cat', (string)$fc) ?><input name="q" value="<?= h($fq) ?>" placeholder="Name, company, host or mobile"><button class="btn sm">Show</button>
<a class="btn sm ghost" href="?view=csv&from=<?= h($from) ?>&to=<?= h($tod) ?>&branch=<?= $fb ?>&cat=<?= $fc ?>&q=<?= h(urlencode($fq)) ?>">Download CSV</a></form>
<div class="grid"><div class="card"><b><?= $rep['total'] ?></b><div class="muted">Visits</div></div><div class="card"><b><?= $rep['avg_min'] ?? '—' ?></b><div class="muted">Average minutes inside</div></div>
<div class="card"><b><?= (int)($rep['status']['cancelled'] ?? 0) ?></b><div class="muted">Left without entering</div></div></div>
<div class="grid"><div class="card"><h3>By category</h3><?php foreach (array_slice($rep['category'], 0, 10, true) as $k => $n): ?><div><?= h($k ?: '—') ?> <span class="muted"><?= $n ?></span></div><?php endforeach; ?></div>
<div class="card"><h3>By branch</h3><?php foreach ($rep['branch'] as $k => $n): ?><div><?= h($allBn[(int)$k] ?? '') ?> <span class="muted"><?= $n ?></span></div><?php endforeach; ?></div>
<div class="card"><h3>Most visited</h3><?php foreach (array_slice($rep['host'], 0, 10, true) as $k => $n): ?><div><?= h($k) ?> <span class="muted"><?= $n ?></span></div><?php endforeach; ?></div></div>
<div class="card"><table><tr><th>Visit</th><th>Visitor</th><th>Meeting</th><th>Branch</th><th>Status</th><th>In</th><th>Out</th></tr>
<?php foreach (array_slice($rows, 0, 300) as $r): ?><tr><td><a href="?view=visit&id=<?= (int)$r['id'] ?>"><?= h((string)$r['visit_no']) ?></a></td><td><?= h($r['name']) ?><div class="muted"><?= h((string)$r['company']) ?></div></td><td><?= h($r['host_name']) ?></td><td><?= h($allBn[(int)$r['branch_id']] ?? '') ?></td><td><?= h(VIS_STATUS[$r['status']]) ?></td><td><?= h(substr((string)$r['check_in_at'], 0, 16)) ?></td><td><?= h(substr((string)$r['check_out_at'], 11, 5)) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="muted">No visits.</td></tr><?php endif; ?></table><?php if (count($rows) > 300): ?><p class="muted">First 300 shown — the CSV has all <?= count($rows) ?>.</p><?php endif; ?></div>

<?php elseif ($view === 'confirm'): $next = (string)($_GET['next'] ?? 'index.php'); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="reauth"><input type="hidden" name="next" value="<?= h($next) ?>"><h2>Confirm your password</h2>
<p class="muted">Needed before visitor details are downloaded or removed; it stays confirmed for <?= VIS_REAUTH_MIN ?> minutes.</p><input type="password" name="password" required autocomplete="current-password"><button class="btn">Confirm</button></form>

<?php elseif ($view === 'retention' && $R['purge']): $due = vis_retention_due($db, (int)$set['keep_months']); $last = $db->query('SELECT * FROM vis_backup ORDER BY id DESC LIMIT 1')->fetch(); ?>
<div class="card"><h2>Old visitor details</h2><p>Visitors not seen for <?= (int)$set['keep_months'] ?> months: <b><?= count($due) ?></b>. Their details are removed after a backup; visit counts stay.</p>
<p class="muted">Last backup: <?= $last ? h((string)$last['at']) . ' (' . (int)$last['rows_n'] . ' visitors)' : 'none' ?></p>
<div class="row"><a class="btn ghost" href="?view=backup">1 · Download the backup</a>
<form method="post" onsubmit="return confirm('Remove the details of these visitors for good?')"><?= csrf_field() ?><input type="hidden" name="a" value="purge"><button class="btn"<?= !$due ? ' disabled' : '' ?>>2 · Remove their details</button></form></div>
<?php if (!vis_reauth_fresh()): ?><p class="muted">You will be asked for your password.</p><?php endif; ?></div>

<?php elseif ($view === 'settings' && $R['configure']): $roles = role_labels(); ?>
<div class="card"><h2>Categories</h2><table><?php foreach (vis_categories($db, false) as $c): ?><tr><td><form method="post" class="row"><?= $hid('cat', (int)$c['id']) ?><input name="label" value="<?= h($c['label']) ?>"><input type="number" name="sort" value="<?= (int)$c['sort'] ?>" style="width:70px"><label><input type="checkbox" name="active" value="1"<?= (int)$c['active'] ? ' checked' : '' ?>> In use</label><button class="btn sm ghost">Save</button></form></td></tr><?php endforeach; ?>
<tr><td><form method="post" class="row"><?= $hid('cat', 0) ?><input name="label" placeholder="New category"><input type="number" name="sort" value="50" style="width:70px"><input type="hidden" name="active" value="1"><button class="btn sm">Add</button></form></td></tr></table></div>
<?php if ($isAdmin): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['desk_roles' => 'The desk (register, host answer, check in / out)', 'report_roles' => 'Register, reports and CSV', 'configure_roles' => 'Categories, edit details, correct times', 'purge_roles' => 'Remove old details'] as $k => $l): ?>
<h3><?= h($l) ?></h3><div class="row" style="flex-wrap:wrap"><?php foreach ($roles as $code => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<div class="grid"><label>Keep details for (months after the last visit) <input type="number" name="keep_months" min="1" max="120" value="<?= (int)$set['keep_months'] ?>"></label>
<label>Mark inside after (hours) <input type="number" name="overdue_hours" min="1" max="48" value="<?= (int)$set['overdue_hours'] ?>"></label><label>Mobile look-ups per login per hour <input type="number" name="lookup_hour" min="5" max="1000" value="<?= (int)$set['lookup_hour'] ?>"></label></div>
<label><input type="checkbox" name="notify_host" value="1"<?= $set['notify_host'] ? ' checked' : '' ?>> Email the host when someone waits for them (Email must be on)</label>
<label>Privacy line on the pass <textarea name="privacy" rows="2"><?= h($set['privacy']) ?></textarea></label>
<p class="muted">Reception logins work only in the branches ticked for them on Users; with none ticked they see no branch.</p><button class="btn">Save</button></form><?php endif; ?>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
