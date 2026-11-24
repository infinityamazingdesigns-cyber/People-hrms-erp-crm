<?php
// Training register — my trainings, one person, one training, add / change, to verify (HR), requests, peer sessions,
// yearly report + CSV, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/training.php';
$u = module_require('training');
$db = pdo(); $set = tr_settings(); $uid = (int)$u['id']; $me = strtoupper((string)($u['emp_code'] ?? ''));
$hr = tr_is_hr($u); $all = tr_sees_all($u); $isAdmin = has_role($u, 'SUPERADMIN');
$view = (string)($_GET['view'] ?? 'mine');
$fyNow = tr_fy(date('Y-m-d'), tr_fy_start());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $code = strtoupper((string)($_POST['code'] ?? ''));
    $to = 'index.php?view=' . urlencode($view) . ($id ? '&id=' . $id : '') . ($code !== '' ? '&code=' . urlencode($code) : '');
    try {
        switch ($a) {
            case 'save': $id = tr_save($db, $u, $id, $code ?: $me, $_POST); flash('Saved.'); $to = 'index.php?view=rec&id=' . $id; break;
            case 'verify': tr_verify($db, $u, $id, ($_POST['ok'] ?? '') === '1', (string)($_POST['note'] ?? '')); flash('Saved.'); break;
            case 'remove': tr_remove($db, $u, $id, (string)($_POST['reason'] ?? '')); flash('Taken off.'); break;
            case 'restore': tr_remove($db, $u, $id, '', true); flash('Brought back.'); break;
            case 'file': $t = $_FILES['f']['tmp_name'] ?? ''; if (!is_string($t) || $t === '' || !is_uploaded_file($t)) throw new RuntimeException('Pick a file.'); tr_file_add($db, $u, $id, (string)file_get_contents($t), (string)$_FILES['f']['name']); flash('Certificate added.'); break;
            case 'feedback': tr_feedback($db, $u, $id, $_POST); flash('Thank you for the feedback.'); break;
            case 'request': tr_request($db, $u, $code ?: $me, $_POST); flash('Request sent.'); $to = 'index.php?view=requests'; break;
            case 'decide': tr_req_decide($db, $u, $id, ($_POST['ok'] ?? '') === '1', (string)($_POST['note'] ?? '')); flash('Saved.'); $to = 'index.php?view=requests'; break;
            case 'withdraw': tr_req_withdraw($db, $u, $id); flash('Withdrawn.'); $to = 'index.php?view=requests'; break;
            case 'session': $id = tr_session_add($db, $u, $_POST); flash('Session arranged.'); $to = 'index.php?view=peer'; break;
            case 'join': case 'leave': tr_session_join($db, $u, $id, $a === 'join'); flash($a === 'join' ? 'You joined.' : 'You left the session.'); $to = 'index.php?view=peer'; break;
            case 'held': $n = tr_session_held($db, $u, $id); flash("Marked held; $n attendee records wait for HR."); $to = 'index.php?view=peer'; break;
            case 'cancel': tr_session_cancel($db, $u, $id, (string)($_POST['note'] ?? '')); flash('Cancelled.'); $to = 'index.php?view=peer'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = tr_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['training'] ?? ['roles' => module_roles('training')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['training', 1, implode(',', $st['roles'] ?: module_roles('training')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'training'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

if ($view === 'file') {
    $s = $db->prepare('SELECT f.*, r.emp_code FROM tr_file f JOIN tr_record r ON r.id = f.record_id WHERE f.id = ? AND f.removed_at IS NULL'); $s->execute([(string)($_GET['f'] ?? '')]); $f = $s->fetch();
    if (!$f || !tr_can_see($db, $u, $f['emp_code'])) { http_response_code(404); exit('Not found'); }
    header('Content-Type: ' . $f['type']); header('Content-Disposition: inline; filename="' . str_replace('"', '', $f['name']) . '"');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    echo is_resource($f['data']) ? stream_get_contents($f['data']) : $f['data']; exit;
}
$fy = preg_match('/^\d{4}(-\d{2})?$/', (string)($_GET['fy'] ?? '')) ? (string)$_GET['fy'] : $fyNow;
if ($view === 'csv' && ($all || tr_below($db, $u))) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="training-' . $fy . '.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('tr_csv_cell', ['Code', 'Name', 'Department', 'Training', 'Kind', 'From', 'To', 'Hours counted', 'Trainer', 'Result', 'Skills', 'Certificate'])) . "\r\n";
    foreach (tr_year_rows($db, $u, $fy) as $r) echo implode(',', array_map('tr_csv_cell', [$r['emp_code'], $r['name'], $r['department'], $r['title'], $r['kind'], $r['start_on'], $r['end_on'], tr_hours($r, (int)$set['day_hours']), $r['trainer'], $r['result'], $r['skills'], (int)$r['files'] ? 'yes' : 'no'])) . "\r\n";
    audit($uid, 'training_export', $fy); exit;
}

$hid = fn(string $a, int $id = 0, string $code = '') => csrf_field() . '<input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="code" value="' . h($code) . '">';
$waitReq = tr_req_waiting($db, $u);
$toVerify = []; if ($hr) $toVerify = array_values(array_filter($db->query("SELECT r.*, e.name, e.branch_id FROM tr_record r LEFT JOIN employee e ON e.code = r.emp_code WHERE r.verify = 'pending' AND r.removed_at IS NULL ORDER BY r.id")->fetchAll(), fn($r) => emp_in_scope($u, $r) && strcasecmp($r['emp_code'], $me) !== 0));
$below = tr_below($db, $u);
$list = function (array $rows, bool $who = false): void { ?>
<table><tr><?php if ($who): ?><th>Person</th><?php endif; ?><th>Training</th><th>Kind</th><th>When</th><th>Status</th><th>HR</th></tr>
<?php foreach ($rows as $r): ?><tr><?php if ($who): ?><td><?= h((string)($r['name'] ?? $r['emp_code'])) ?></td><?php endif; ?><td><a href="?view=rec&id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a><?= (int)($r['files'] ?? 0) ? ' 📎' : '' ?></td><td><?= h($r['kind']) ?></td>
<td><?= h((string)$r['start_on']) ?><?= $r['end_on'] && $r['end_on'] !== $r['start_on'] ? ' – ' . h((string)$r['end_on']) : '' ?></td><td><?= h(TR_STATUS[$r['status']]) ?></td><td><?= h(TR_VERIFY[$r['verify']]) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">None.</td></tr><?php endif; ?></table>
<?php };
$form = function (array $r, string $code) use ($hid, $hr, $set): void { ?>
<form method="post" class="card"><?= $hid('save', (int)($r['id'] ?? 0), $code) ?><h2><?= !empty($r['id']) ? 'Change the training' : 'Add a training' ?></h2>
<div class="grid"><label>Training * <input name="title" value="<?= h((string)($r['title'] ?? '')) ?>" required></label>
<label>Kind * <select name="kind" required><option value="">—</option><?php foreach (TR_KINDS as $k): ?><option<?= ($r['kind'] ?? '') === $k ? ' selected' : '' ?>><?= h($k) ?></option><?php endforeach; ?></select></label>
<label>Status <select name="status"><?php foreach (TR_STATUS as $k => $l): ?><option value="<?= $k ?>"<?= ($r['status'] ?? 'completed') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
<label>From * <input type="date" name="start_on" value="<?= h((string)($r['start_on'] ?? '')) ?>" required></label><label>To <input type="date" name="end_on" value="<?= h((string)($r['end_on'] ?? '')) ?>"></label>
<label>Hours <input name="hours" value="<?= h((string)($r['hours'] ?? '')) ?>" inputmode="decimal"></label><label>Days <input name="days" value="<?= h((string)($r['days'] ?? '')) ?>" inputmode="decimal"></label>
<label>Trainer / provider <input name="trainer" value="<?= h((string)($r['trainer'] ?? '')) ?>"></label><label>Venue <input name="venue" value="<?= h((string)($r['venue'] ?? '')) ?>"></label>
<label>How it was evaluated <input name="evaluated" value="<?= h((string)($r['evaluated'] ?? '')) ?>"></label><label>Result <input name="result" value="<?= h((string)($r['result'] ?? '')) ?>"></label>
<?php if ($hr && $set['show_fee']): ?><label>Fee <input name="fee" value="<?= h((string)($r['fee'] ?? '')) ?>"></label><?php endif; ?></div>
<label>Skills (comma separated) <input name="skills" value="<?= h((string)($r['skills'] ?? '')) ?>"></label><label>Link (https) <input name="link" value="<?= h((string)($r['link'] ?? '')) ?>"></label>
<label>Objectives <textarea name="objectives" rows="2"><?= h((string)($r['objectives'] ?? '')) ?></textarea></label><label>Outcome <textarea name="outcome" rows="2"><?= h((string)($r['outcome'] ?? '')) ?></textarea></label>
<button class="btn">Save</button><?php if (!$hr): ?> <span class="muted">HR verifies what you add or change.</span><?php endif; ?></form>
<?php };
ui_head('Training register', $u, 'training'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'mine' ? '' : ' ghost' ?>" href="index.php">My trainings</a>
<?php if ($below || $all): ?> <a class="btn sm<?= $view === 'people' ? '' : ' ghost' ?>" href="?view=people">People</a><?php endif; ?>
<?php if ($hr): ?> <a class="btn sm<?= $view === 'verify' ? '' : ' ghost' ?>" href="?view=verify">To verify<?= $toVerify ? ' (' . count($toVerify) . ')' : '' ?></a><?php endif; ?>
 <a class="btn sm<?= $view === 'requests' ? '' : ' ghost' ?>" href="?view=requests">Requests<?= $waitReq ? ' (' . count($waitReq) . ')' : '' ?></a> <a class="btn sm<?= $view === 'peer' ? '' : ' ghost' ?>" href="?view=peer">Peer sessions</a>
<?php if ($below || $all): ?> <a class="btn sm<?= $view === 'report' ? '' : ' ghost' ?>" href="?view=report">Year report</a><?php endif; ?>
<?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php if ($view === 'mine'): ?>
<?php if ($me === ''): ?><div class="card">Your login is not linked to an employee code — ask HR.</div><?php else: $rows = tr_of($db, $me);
    $fbDue = array_filter($rows, fn($r) => !(int)$r['fb'] && tr_feedback_open($r, date('Y-m-d'), (int)$set['feedback_days'])); ?>
<?php foreach ($rows as $r) if ($r['verify'] === 'info'): ?><div class="card"><b>HR asked for details</b> on <a href="?view=rec&id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a>: <?= h($r['ask_note']) ?></div><?php endif; ?>
<?php if ($fbDue): ?><div class="card"><b>Tell us how it went:</b> <?php foreach ($fbDue as $r): ?><a href="?view=rec&id=<?= (int)$r['id'] ?>#fb"><?= h($r['title']) ?></a> <?php endforeach; ?></div><?php endif; ?>
<div class="card"><div class="row"><h2>My trainings</h2> <a class="btn sm" href="?view=add">Add a training</a> <a class="btn sm ghost" href="?view=ask">Ask for a training</a></div><?php $list($rows); ?></div>
<?php endif;

elseif ($view === 'add' || $view === 'ask'): $code = strtoupper((string)($_GET['code'] ?? $me));
    if ($code === '' || !tr_can_add($db, $u, $code)): ?><div class="card">Not available.</div><?php elseif ($view === 'add'): $form([], $code); else: ?>
<form method="post" class="card"><?= $hid('request', 0, $code) ?><h2>Ask for a training<?= $code !== $me ? ' for ' . h((string)(emp_by_code($db, $code)['name'] ?? '')) : '' ?></h2>
<div class="grid"><label>Training * <input name="title" required></label><label>Kind <select name="kind"><?php foreach (TR_KINDS as $k): ?><option><?= h($k) ?></option><?php endforeach; ?></select></label>
<label>When <input name="when_hint" placeholder="e.g. next month"></label><label>Provider <input name="provider"></label><label>Likely cost <input name="cost"></label></div>
<label>Why it is needed * <textarea name="why" rows="3" required></textarea></label><button class="btn">Send</button>
<p class="muted"><?= $code === $me ? 'It goes to your reporting manager, then HR.' : 'It goes to HR.' ?></p></form>
<?php endif;

elseif ($view === 'people' && ($below || $all)):
    if ($all) $people = array_values(array_filter($db->query('SELECT code, name, department, branch_id FROM employee WHERE active = 1 ORDER BY name')->fetchAll(), fn($p) => emp_in_scope($u, $p)));
    else { $s = $db->prepare('SELECT code, name, department FROM employee WHERE active = 1 AND code IN (' . implode(',', array_fill(0, count($below), '?')) . ') ORDER BY name'); $s->execute($below); $people = $s->fetchAll(); }
    $code = strtoupper((string)($_GET['code'] ?? ''));
    if ($code !== '' && tr_can_see($db, $u, $code)): $e = emp_by_code($db, $code); ?>
<div class="card"><div class="row"><h2><?= h((string)$e['name']) ?></h2><?php if (tr_can_add($db, $u, $code)): ?> <a class="btn sm" href="?view=add&code=<?= h(urlencode($code)) ?>">Add a training</a> <a class="btn sm ghost" href="?view=ask&code=<?= h(urlencode($code)) ?>">Ask for one</a><?php endif; ?></div><?php $list(tr_of($db, $code)); ?></div>
<?php else: ?><div class="card"><h2>People</h2><input id="pq" placeholder="Find a person" style="max-width:300px"><table id="pl"><?php foreach ($people as $p): ?><tr data-q="<?= h(mb_strtolower($p['name'] . ' ' . $p['department'])) ?>"><td><a href="?view=people&code=<?= h(urlencode($p['code'])) ?>"><?= h($p['name']) ?></a></td><td class="muted"><?= h($p['department']) ?></td></tr><?php endforeach; ?></table></div>
<script>document.getElementById('pq').addEventListener('input',function(e){var t=e.target.value.toLowerCase();document.querySelectorAll('#pl tr').forEach(function(r){r.style.display=!t||r.dataset.q.indexOf(t)>=0?'':'none';});});</script><?php endif;

elseif ($view === 'rec'):
    $r = tr_get($db, (int)($_GET['id'] ?? 0));
    if (!$r || !tr_can_see($db, $u, $r['emp_code'])): ?><div class="card">Not found.</div><?php else:
    $own = strcasecmp($r['emp_code'], $me) === 0; $canEdit = $r['removed_at'] === null && tr_can_add($db, $u, $r['emp_code']);
    $f = $db->prepare('SELECT id, name, size FROM tr_file WHERE record_id = ? AND removed_at IS NULL'); $f->execute([(int)$r['id']]); $files = $f->fetchAll();
    $fb = $db->prepare('SELECT * FROM tr_feedback WHERE record_id = ?'); $fb->execute([(int)$r['id']]); $fb = $fb->fetch();
    $log = $db->prepare("SELECT l.*, u.name FROM tr_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.kind = 'rec' AND l.ref_id = ? ORDER BY l.id"); $log->execute([(int)$r['id']]); ?>
<div class="card"><h2><?= h($r['title']) ?> <span class="muted"><?= h((string)$r['name']) ?></span></h2>
<p><?= h(implode(' · ', array_filter([$r['kind'], TR_STATUS[$r['status']], $r['start_on'] . ($r['end_on'] && $r['end_on'] !== $r['start_on'] ? ' – ' . $r['end_on'] : ''), $r['hours'] !== null ? (float)$r['hours'] . ' h' : ($r['days'] !== null ? (float)$r['days'] . ' days' : ''), $r['trainer'], $r['venue']]))) ?></p>
<p><b><?= h(TR_VERIFY[$r['verify']]) ?></b><?= $r['verify'] === 'info' ? ' — ' . h($r['ask_note']) : '' ?><?= $r['removed_at'] ? ' · <span class="muted">taken off: ' . h($r['removed_reason']) . '</span>' : '' ?></p>
<?php foreach (['objectives' => 'Objectives', 'outcome' => 'Outcome', 'evaluated' => 'Evaluated by', 'result' => 'Result', 'skills' => 'Skills'] as $k => $l) if ((string)$r[$k] !== ''): ?><p><b><?= h($l) ?>:</b> <?= h((string)$r[$k]) ?></p><?php endif; ?>
<?php if ($r['link']): ?><p><a href="<?= h($r['link']) ?>" rel="noopener" target="_blank">Link</a></p><?php endif; ?><?php if ($hr && $r['fee'] !== null): ?><p class="muted">Fee <?= h((string)$r['fee']) ?></p><?php endif; ?>
<h3>Certificates</h3><?php foreach ($files as $x): ?><div><a href="?view=file&f=<?= h($x['id']) ?>" target="_blank"><?= h($x['name']) ?></a></div><?php endforeach; ?>
<?php if ($canEdit): ?><form method="post" enctype="multipart/form-data" class="row"><?= $hid('file', (int)$r['id']) ?><input type="file" name="f" accept="application/pdf,image/*" required aria-label="File"><button class="btn sm ghost">Add certificate</button></form><?php endif; ?></div>
<?php if ($hr && !$own && $r['removed_at'] === null && $r['verify'] !== 'verified' && emp_in_scope($u, $r)): ?><form method="post" class="card row"><?= $hid('verify', (int)$r['id']) ?><input name="note" placeholder="What details or proof are needed"><button class="btn sm" name="ok" value="1">Verify</button><button class="btn sm ghost" name="ok" value="0">Ask for details</button></form><?php endif; ?>
<?php if ($own && !$fb && tr_feedback_open($r, date('Y-m-d'), (int)$set['feedback_days'])): ?><form method="post" class="card" id="fb"><?= $hid('feedback', (int)$r['id']) ?><h3>How was it?</h3><div class="row"><select name="rating" required><option value="">Rating</option><?php for ($n = 1; $n <= 5; $n++): ?><option value="<?= $n ?>"><?= str_repeat('★', $n) ?></option><?php endfor; ?></select><label><input type="checkbox" name="recommend" value="1"> I would recommend it</label></div>
<textarea name="learned" rows="2" placeholder="What you learned"></textarea><textarea name="use_plan" rows="2" placeholder="How you will use it"></textarea><button class="btn sm">Send</button></form><?php endif; ?>
<?php if ($fb): ?><div class="card"><h3>Feedback</h3><p><?= str_repeat('★', (int)$fb['rating']) ?><?= (int)$fb['recommend'] ? ' · recommends it' : '' ?></p><p><?= h($fb['learned']) ?></p><p class="muted"><?= h($fb['use_plan']) ?></p></div><?php endif; ?>
<?php if ($canEdit): ?><details><summary>Change it</summary><?php $form($r, $r['emp_code']); ?></details>
<form method="post" class="card row"><?= $hid('remove', (int)$r['id']) ?><input name="reason" placeholder="Why it is taken off" required><button class="btn sm ghost">Take off</button></form><?php endif; ?>
<?php if ($hr && $r['removed_at'] !== null): ?><form method="post"><?= $hid('restore', (int)$r['id']) ?><button class="btn sm ghost">Bring back</button></form><?php endif; ?>
<div class="card"><h3>History</h3><?php foreach ($log as $l): ?><div class="muted"><?= h((string)$l['at']) ?> · <?= h((string)$l['name']) ?> · <?= h($l['action']) ?><?= $l['fields'] !== '' ? ' (' . h($l['fields']) . ')' : '' ?></div><?php endforeach; ?></div>
<?php endif;

elseif ($view === 'verify' && $hr): ?>
<div class="card"><h2>Trainings to verify</h2><?php $list($toVerify, true); ?></div>

<?php elseif ($view === 'requests'):
    $minev = $db->prepare('SELECT q.*, e.name FROM tr_request q LEFT JOIN employee e ON e.code = q.emp_code WHERE q.by_user = ? OR q.emp_code = ? ORDER BY q.id DESC LIMIT 50'); $minev->execute([$uid, $me]); ?>
<?php if ($waitReq): ?><div class="card"><h2>Waiting for you</h2><?php foreach ($waitReq as $q): ?><form method="post" class="card"><?= $hid('decide', (int)$q['id']) ?><b><?= h((string)$q['name']) ?></b> — <?= h($q['title']) ?> <span class="muted">(<?= h($q['kind']) ?><?= $q['when_hint'] ? ', ' . h($q['when_hint']) : '' ?><?= $q['provider'] ? ', ' . h($q['provider']) : '' ?><?= $hr && $q['cost'] !== null ? ', cost ' . h((string)$q['cost']) : '' ?>)</span>
<p><?= h($q['why']) ?></p><?= $q['mgr_note'] ? '<p class="muted">Manager: ' . h($q['mgr_note']) . '</p>' : '' ?><div class="row"><input name="note" placeholder="Note (needed to decline)"><button class="btn sm" name="ok" value="1">Approve</button><button class="btn sm ghost" name="ok" value="0">Decline</button></div></form><?php endforeach; ?></div><?php endif; ?>
<div class="card"><div class="row"><h2>My requests</h2><?php if ($me !== ''): ?> <a class="btn sm" href="?view=ask">Ask for a training</a><?php endif; ?></div><table><?php foreach ($minev as $q): ?><tr><td><?= h($q['title']) ?><?= strcasecmp($q['emp_code'], $me) !== 0 ? ' <span class="muted">for ' . h((string)$q['name']) . '</span>' : '' ?></td><td><?= h(TR_REQ[$q['status']]) ?></td><td class="muted"><?= h(trim($q['mgr_note'] . ' ' . $q['hr_note'])) ?></td>
<td><?php if ((int)$q['by_user'] === $uid && in_array($q['status'], ['manager', 'hr'], true)): ?><form method="post"><?= $hid('withdraw', (int)$q['id']) ?><button class="btn sm ghost">Withdraw</button></form><?php endif; ?><?= $q['record_id'] ? '<a href="?view=rec&id=' . (int)$q['record_id'] . '">Open</a>' : '' ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($view === 'peer'): $ss = $db->query("SELECT s.*, e.name AS trainer, (SELECT COUNT(*) FROM tr_attendee a WHERE a.session_id = s.id AND a.left_at IS NULL) AS n FROM tr_session s LEFT JOIN employee e ON e.code = s.trainer_code WHERE s.status = 'open' OR s.at >= DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY s.at DESC")->fetchAll();
    $mineJ = $db->prepare('SELECT session_id FROM tr_attendee WHERE emp_code = ? AND left_at IS NULL'); $mineJ->execute([$me]); $joined = array_flip($mineJ->fetchAll(PDO::FETCH_COLUMN)); ?>
<div class="card"><h2>Peer sessions</h2><table><tr><th>Session</th><th>Trainer</th><th>When</th><th>Seats</th><th></th></tr>
<?php foreach ($ss as $s): $mineS = $s['trainer_code'] === $me || (int)$s['by_user'] === $uid; ?><tr><td><b><?= h($s['title']) ?></b><div class="muted"><?= h($s['about']) ?></div></td><td><?= h((string)$s['trainer']) ?></td><td><?= h(date('D j M, H:i', strtotime((string)$s['at']))) ?> · <?= (float)$s['hours'] ?> h<?= $s['venue'] ? ' · ' . h($s['venue']) : '' ?></td><td><?= (int)$s['n'] ?>/<?= (int)$s['seats'] ?></td>
<td><?php if ($s['status'] !== 'open'): ?><?= h($s['status']) ?><?php else: ?>
<?php if ($me !== '' && $s['trainer_code'] !== $me): ?><form method="post"><?= $hid(isset($joined[(int)$s['id']]) ? 'leave' : 'join', (int)$s['id']) ?><button class="btn sm<?= isset($joined[(int)$s['id']]) ? ' ghost' : '' ?>"><?= isset($joined[(int)$s['id']]) ? 'Leave' : 'Join' ?></button></form><?php endif; ?>
<?php if ($mineS || $hr): ?><form method="post"><?= $hid('held', (int)$s['id']) ?><button class="btn sm ghost">Mark held</button></form><form method="post" class="row"><?= $hid('cancel', (int)$s['id']) ?><input name="note" placeholder="Why"><button class="btn sm ghost">Cancel</button></form><?php endif; ?>
<?php endif; ?></td></tr><?php endforeach; ?><?php if (!$ss): ?><tr><td colspan="5" class="muted">No sessions.</td></tr><?php endif; ?></table></div>
<?php if ($me !== '' || $hr): ?><form method="post" class="card"><?= $hid('session') ?><h3>Arrange a session</h3><div class="grid"><label>Title * <input name="title" required></label><label>When * <input type="datetime-local" name="at" required></label><label>Hours * <input name="hours" value="1"></label><label>Seats <input type="number" name="seats" value="20"></label><label>Venue <input name="venue"></label>
<?php if ($hr): ?><label>Trainer (employee code, blank = you) <input name="trainer_code"></label><?php endif; ?></div><label>What it covers <textarea name="about" rows="2"></textarea></label><button class="btn sm">Arrange</button></form><?php endif; ?>

<?php elseif ($view === 'report' && ($below || $all)): $rows = tr_year_rows($db, $u, $fy); $rp = tr_report($rows, (int)$set['day_hours']);
    [, $pf] = [0, tr_fy(date('Y-m-d', strtotime(tr_fy_range($fy, tr_fy_start())[0] . ' -1 day')), tr_fy_start())]; $nf = tr_fy(date('Y-m-d', strtotime(tr_fy_range($fy, tr_fy_start())[1] . ' +1 day')), tr_fy_start()); ?>
<div class="row"><a class="btn sm ghost" href="?view=report&fy=<?= h($pf) ?>">‹</a> <b>Year <?= h($fy) ?></b> <a class="btn sm ghost" href="?view=report&fy=<?= h($nf) ?>">›</a> <a class="btn sm ghost" href="?view=csv&fy=<?= h($fy) ?>">Download CSV</a></div>
<div class="grid"><div class="card"><b><?= $rp['count'] ?></b><div class="muted">Verified trainings</div></div><div class="card"><b><?= round($rp['hours'], 1) ?></b><div class="muted">Hours</div></div><div class="card"><b><?= count($rp['people']) ?></b><div class="muted">People trained</div></div><div class="card"><b><?= $rp['certs'] ?></b><div class="muted">With a certificate</div></div></div>
<div class="grid"><div class="card"><h3>By kind</h3><?php foreach ($rp['kind'] as $k => $n): ?><div><?= h($k) ?> <span class="muted"><?= $n ?></span></div><?php endforeach; ?></div>
<div class="card"><h3>Skills</h3><?php foreach (array_slice($rp['skills'], 0, 15, true) as $k => $n): ?><div><?= h($k) ?> <span class="muted"><?= $n ?></span></div><?php endforeach; ?></div></div>
<div class="card"><h3>By person</h3><table><tr><th>Person</th><th>Trainings</th><th>Hours</th></tr><?php foreach ($rp['people'] as $p): ?><tr><td><?= h((string)$p['name']) ?></td><td><?= $p['count'] ?></td><td><?= round($p['hours'], 1) ?></td></tr><?php endforeach; ?></table>
<p class="muted">A training with days but no hours counts <?= (int)$set['day_hours'] ?> hours a day. Only verified, completed trainings are counted.</p></div>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['hr_roles' => 'HR (verify, decide requests, see everyone)', 'see_all_roles' => 'See everyone (read only)'] as $k => $l): ?><h3><?= h($l) ?></h3><div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $c => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($c) ?>"<?= in_array($c, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<div class="grid"><label>Feedback asked for (days after) <input type="number" name="feedback_days" value="<?= (int)$set['feedback_days'] ?>"></label><label>Hours in a training day <input type="number" name="day_hours" value="<?= (int)$set['day_hours'] ?>"></label></div>
<label><input type="checkbox" name="show_fee" value="1"<?= $set['show_fee'] ? ' checked' : '' ?>> HR records the fee of a training</label><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
