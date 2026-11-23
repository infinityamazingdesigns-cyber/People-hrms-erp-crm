<?php
// Feedback & conduct — home (waiting, my letters and notes, my people), one person, one case (+ letter), check-ins,
// code of conduct, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/conduct.php';
$u = module_require('conduct');
$db = pdo(); $set = dc_settings(); $uid = (int)$u['id']; $me = strtoupper((string)($u['emp_code'] ?? ''));
$hr = dc_is_hr($u); $isAdmin = has_role($u, 'SUPERADMIN');
$view = (string)($_GET['view'] ?? 'home');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $code = strtoupper((string)($_POST['code'] ?? ''));
    $to = $id ? 'index.php?view=case&id=' . $id : ($code !== '' ? 'index.php?view=person&code=' . urlencode($code) : 'index.php?view=' . urlencode($view));
    try {
        switch ($a) {
            case 'note': dc_note_add($db, $u, $code, $_POST); flash('Note saved.'); break;
            case 'noteoff': dc_note_remove($db, $u, (int)$_POST['note'], (string)($_POST['reason'] ?? '')); flash('Taken off.'); break;
            case 'checkin': dc_checkin($db, $u, $code, (string)$_POST['ym'], (string)($_POST['state'] ?? ''), (string)($_POST['text'] ?? '')); flash('Check-in saved.'); if (!empty($_POST['back'])) $to = 'index.php?view=checkins'; break;
            case 'raise': $id = dc_raise($db, $u, $_POST + ['emp_code' => $code]); flash('Case raised — it goes to HR for review.'); $to = 'index.php?view=case&id=' . $id; break;
            case 'hr': dc_hr_review($db, $u, $id, (string)$_POST['act'], $_POST); flash('Saved.'); break;
            case 'approve': dc_approve($db, $u, $id, ($_POST['ok'] ?? '') === '1', (string)($_POST['note'] ?? '')); flash('Saved.'); break;
            case 'resend': dc_resend($db, $u, $id, $_POST); flash('Sent to HR again.'); break;
            case 'ack': dc_reply($db, $u, $id, '', true); flash('Acknowledged.'); break;
            case 'reply': dc_reply($db, $u, $id, (string)($_POST['text'] ?? ''), false); flash('Reply saved.'); break;
            case 'close': dc_close($db, $u, $id, (string)($_POST['outcome'] ?? '')); flash('Closed.'); break;
            case 'appeal': dc_appeal($db, $u, $id, (string)($_POST['text'] ?? '')); flash('Appeal sent.'); break;
            case 'appealdecide': dc_appeal_decide($db, $u, $id, ($_POST['upheld'] ?? '') === '1', (string)($_POST['note'] ?? '')); flash('Decided.'); break;
            case 'file': $t = $_FILES['f']['tmp_name'] ?? ''; if (!is_string($t) || $t === '' || !is_uploaded_file($t)) throw new RuntimeException('Pick a file.'); dc_file_add($db, $u, $id, (string)file_get_contents($t), (string)$_FILES['f']['name'], (string)($_POST['note'] ?? '')); flash('Evidence added.'); break;
            case 'accept': $v = dc_code_version_of($db->query('SELECT * FROM dc_code')->fetchAll()); if ($v === '') throw new RuntimeException('There is no code yet.'); $db->prepare('INSERT IGNORE INTO dc_code_ack (user_id, version) VALUES (?,?)')->execute([$uid, $v]); flash('Thank you — accepted.'); $to = 'index.php?view=code'; break;
            case 'clause': dc_code_save($db, $u, (int)($_POST['cid'] ?? 0), $_POST); flash('Saved. Everyone is asked to accept the new version.'); $to = 'index.php?view=code'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = dc_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['conduct'] ?? ['roles' => module_roles('conduct')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['conduct', 1, implode(',', $st['roles'] ?: module_roles('conduct')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'conduct'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

if ($view === 'file') {
    $s = $db->prepare('SELECT f.*, c.emp_code FROM dc_file f JOIN dc_case c ON c.id = f.case_id WHERE f.id = ?'); $s->execute([(string)($_GET['f'] ?? '')]); $f = $s->fetch();
    if (!$f || strcasecmp($f['emp_code'], $me) === 0 || !dc_can_see($db, $u, $f['emp_code'])) { http_response_code(404); exit('Not found'); }
    audit($uid, 'dc_file_view', '#' . $f['case_id']);
    header('Content-Type: ' . $f['type']); header('Content-Disposition: inline; filename="' . str_replace('"', '', $f['name']) . '"');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    echo is_resource($f['data']) ? stream_get_contents($f['data']) : $f['data']; exit;
}

if ($view === 'letter') {
    $c = dc_case_get($db, (int)($_GET['id'] ?? 0));
    if (!$c || !dc_case_visible($db, $u, $c)) { http_response_code(404); exit('Not found'); }
    $ack = null; $s = $db->prepare('SELECT u.id FROM users u WHERE u.emp_code = ?'); $s->execute([$c['emp_code']]);
    if ($pu = $s->fetchColumn()) { $q = $db->prepare('SELECT MIN(at) FROM dc_code_ack WHERE user_id = ? AND at <= COALESCE(?, NOW())'); $q->execute([(int)$pu, $c['issued_at']]); $ack = $q->fetchColumn() ?: null; }
    $L = dc_letter($c, $set, (string)$c['name'], $ack); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= h($L['title'] . ' ' . $c['ref']) ?></title><style>body{font:15px/1.6 Georgia,serif;max-width:720px;margin:40px auto;padding:0 20px}h1{font-size:20px}.m{color:#555}.d{color:#b42318;font-weight:700}@media print{button{display:none}}</style></head><body>
<p><b><?= h(org('legal_name') ?: org('name')) ?></b><br><span class="m"><?= h(org('address')) ?></span></p>
<?php if (!$c['issued_at']): ?><p class="d">DRAFT — not issued</p><?php endif; ?>
<p class="m">Ref <?= h((string)$c['ref']) ?> · Date <?= h(date('d/m/Y', strtotime((string)($c['issued_at'] ?: 'now')))) ?></p>
<p><?= h((string)$c['name']) ?> (<?= h($c['emp_code']) ?>)<?= $c['department'] ? '<br>' . h($c['department']) : '' ?></p><h1><?= h($L['subject']) ?></h1><p><?= h($L['dear']) ?></p>
<?php foreach ($L['paras'] as $p): ?><p><?= h($p) ?></p><?php endforeach; ?><p>For <?= h(org('legal_name') ?: org('name')) ?><br><br><?= h($L['sign']) ?></p>
<?php if ($c['ack_at']): ?><p class="m">Received <?= h(date('d/m/Y H:i', strtotime((string)$c['ack_at']))) ?></p><?php endif; ?><button onclick="window.print()">Print</button></body></html>
<?php exit; }

$w = dc_waiting($db, $u);
$hid = fn(string $a, int $id = 0, string $code = '') => csrf_field() . '<input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="code" value="' . h($code) . '">';
$sel = fn(array $o, string $n, string $c = '') => '<select name="' . $n . '">' . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($o), $o)) . '</select>';
ui_head('Feedback & conduct', $u, 'conduct'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'home' ? '' : ' ghost' ?>" href="index.php">Home</a>
<?php if (dc_direct($db, $u)): ?> <a class="btn sm<?= $view === 'checkins' ? '' : ' ghost' ?>" href="?view=checkins">Check-ins<?= $w['checkins'] ? ' (' . $w['checkins'] . ')' : '' ?></a><?php endif; ?>
 <a class="btn sm<?= $view === 'code' ? '' : ' ghost' ?>" href="?view=code">Code of conduct<?= $w['code'] ? ' (to accept)' : '' ?></a>
<?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php if ($view === 'home'):
    $queue = [];
    if ($w['hr'] || $w['approve'] || $w['appeal']) foreach ($db->query("SELECT c.*, e.name, e.branch_id FROM dc_case c LEFT JOIN employee e ON e.code = c.emp_code WHERE c.status IN ('review_hr','review_appr') OR (c.appeal_at IS NOT NULL AND c.appeal_result IS NULL) ORDER BY c.id") as $c) {
        if (strcasecmp($c['emp_code'], $me) === 0 || (int)$c['raised_by'] === $uid) continue;
        if (($c['status'] === 'review_hr' && $hr && emp_in_scope($u, $c)) || ($c['status'] === 'review_appr' && dc_has($u, 'approve_roles')) || ($c['appeal_at'] && !$c['appeal_result'] && dc_has($u, 'judge_roles'))) $queue[] = $c;
    }
    $mine = $me !== '' ? array_values(array_filter(dc_cases_of($db, $me), fn($c) => in_array($c['status'], ['issued', 'closed', 'withdrawn'], true))) : [];
    $notes = []; if ($me !== '') { $s = $db->prepare('SELECT n.*, u.name AS by_name FROM dc_note n LEFT JOIN users u ON u.id = n.by_user WHERE n.emp_code = ? AND n.removed_at IS NULL ORDER BY n.id DESC LIMIT 20'); $s->execute([$me]); $notes = $s->fetchAll(); }
    $raised = $db->prepare("SELECT c.*, e.name FROM dc_case c LEFT JOIN employee e ON e.code = c.emp_code WHERE c.raised_by = ? AND c.status IN ('review_hr','review_appr','returned') ORDER BY c.id DESC"); $raised->execute([$uid]); $raised = $raised->fetchAll();
    $people = [];
    if ($hr || dc_has($u, 'see_all_roles')) $people = array_values(array_filter($db->query('SELECT code, name, department, branch_id FROM employee WHERE active = 1 AND (last_day IS NULL OR last_day >= CURDATE()) ORDER BY name')->fetchAll(), fn($p) => emp_in_scope($u, $p) && strcasecmp($p['code'], $me) !== 0));
    elseif ($below = dc_below($db, $u)) { $s = $db->prepare('SELECT code, name, department FROM employee WHERE active = 1 AND code IN (' . implode(',', array_fill(0, count($below), '?')) . ') ORDER BY name'); $s->execute($below); $people = $s->fetchAll(); } ?>
<?php if ($w['code']): ?><div class="card"><b>Please read and accept the code of conduct.</b> <a class="btn sm" href="?view=code">Open it</a></div><?php endif; ?>
<?php if ($queue): ?><div class="card"><h2>Waiting for you</h2><table><?php foreach ($queue as $c): ?><tr><td><a href="?view=case&id=<?= (int)$c['id'] ?>"><?= h((string)$c['ref']) ?></a></td><td><?= h((string)$c['name']) ?></td><td><?= h(DC_STEPS[$c['step']]) ?> · <?= h(DC_SEV[$c['severity']]) ?></td><td><?= $c['appeal_at'] && !$c['appeal_result'] ? 'Appeal to decide' : h(DC_STATUS[$c['status']]) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php if ($mine): ?><div class="card"><h2>Letters to you</h2><table><?php foreach ($mine as $c): ?><tr><td><a href="?view=case&id=<?= (int)$c['id'] ?>"><?= h((string)$c['ref']) ?></a></td><td><?= h(DC_STEPS[$c['step']]) ?></td><td><?= h(substr((string)$c['issued_at'], 0, 10)) ?></td><td><?= h(DC_STATUS[$c['status']]) ?><?= $c['status'] === 'issued' && !$c['reply_at'] ? ' · <b>reply needed</b>' : '' ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php if ($notes): ?><div class="card"><h2>Feedback for you</h2><?php foreach ($notes as $n): ?><div><b><?= h(DC_NOTE_KINDS[$n['kind']]) ?></b> · <?= h((string)$n['by_name']) ?> · <?= h(substr((string)$n['created_at'], 0, 10)) ?><div><?= h($n['text']) ?></div><?= $n['action'] !== '' ? '<div class="muted">Agreed action: ' . h($n['action']) . '</div>' : '' ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($raised): ?><div class="card"><h2>Cases you raised, in progress</h2><?php foreach ($raised as $c): ?><div><a href="?view=case&id=<?= (int)$c['id'] ?>"><?= h((string)$c['ref']) ?></a> · <?= h((string)$c['name']) ?> · <?= h(DC_STATUS[$c['status']]) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($people): ?><div class="card"><h2>People you can give feedback to</h2><input id="pq" placeholder="Find a person" style="max-width:300px"><table id="pl"><?php foreach ($people as $p): ?><tr data-q="<?= h(mb_strtolower($p['name'] . ' ' . $p['code'] . ' ' . $p['department'])) ?>"><td><a href="?view=person&code=<?= h(urlencode($p['code'])) ?>"><?= h($p['name']) ?></a></td><td class="muted"><?= h($p['department']) ?></td></tr><?php endforeach; ?></table></div>
<script>document.getElementById('pq').addEventListener('input',function(e){var t=e.target.value.toLowerCase();document.querySelectorAll('#pl tr').forEach(function(r){r.style.display=!t||r.dataset.q.indexOf(t)>=0?'':'none';});});</script><?php endif; ?>
<?php if (!$queue && !$mine && !$notes && !$people && !$raised): ?><div class="card muted">Nothing here yet.</div><?php endif; ?>

<?php elseif ($view === 'person'):
    $code = strtoupper((string)($_GET['code'] ?? '')); $e = emp_by_code($db, $code);
    if (!$e || strcasecmp($code, $me) === 0 || !dc_can_see($db, $u, $code)): ?><div class="card">Not found.</div><?php else:
    $can = dc_can_raise($db, $u, $code);
    $n = $db->prepare('SELECT n.*, u.name AS by_name FROM dc_note n LEFT JOIN users u ON u.id = n.by_user WHERE n.emp_code = ? ORDER BY n.id DESC'); $n->execute([$code]);
    $ck = $db->prepare('SELECT c.*, u.name AS by_name FROM dc_checkin c LEFT JOIN users u ON u.id = c.by_user WHERE c.emp_code = ? ORDER BY c.ym DESC LIMIT 12'); $ck->execute([$code]);
    $cases = dc_cases_of($db, $code); $lap = dc_lapse_map($cases, $set['lapse']); $codeCl = $db->query('SELECT id, no, title FROM dc_code WHERE active = 1 ORDER BY sort, id')->fetchAll(); ?>
<div class="card"><h2><?= h($e['name']) ?></h2><p class="muted"><?= h(implode(' · ', array_filter([$e['designation'], $e['department']]))) ?></p></div>
<?php if ($can): ?>
<form method="post" class="card"><?= $hid('note', 0, $code) ?><h3>Give a feedback note</h3><div class="row"><?= $sel(DC_NOTE_KINDS, 'kind') ?></div><textarea name="text" rows="3" placeholder="What you saw" required></textarea>
<input name="action" placeholder="Agreed action (needed for 'Needs improvement')"><?php if (module_on('board')): ?><label><input type="checkbox" name="share" value="1"> Share an appreciation on the announcement board</label><?php endif; ?><button class="btn sm">Save note</button></form>
<details class="card"><summary><b>Raise a conduct case</b></summary><form method="post"><?= $hid('raise', 0, $code) ?>
<div class="grid"><label>Category <?= $sel(DC_CAT, 'category') ?></label><label>How serious <?= $sel(DC_SEV, 'severity') ?></label><label>Day it happened <input type="date" name="inc_date" max="<?= date('Y-m-d') ?>" required></label></div>
<label>What happened <textarea name="what" rows="3" required></textarea></label><label>What is expected from now on <textarea name="expected" rows="2"></textarea></label>
<?php if ($codeCl): ?><label>Code of conduct clauses (up to 5) <select name="clauses[]" multiple size="4"><?php foreach ($codeCl as $cl): ?><option value="<?= (int)$cl['id'] ?>"><?= h($cl['no'] . ' · ' . $cl['title']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<p class="muted"><?php foreach (DC_SEV as $sk => $sl): $ns = dc_next_step($db, $code, $sk); ?><?= h($sl) ?>: <?= h($ns['step'] === 'feedback' ? 'feedback note first' : DC_STEPS[$ns['step']]) ?> · <?php endforeach; ?>HR reviews before anything is issued.</p>
<button class="btn sm">Raise</button></form></details>
<?php endif; ?>
<div class="card"><h3>Notes</h3><?php foreach ($n as $x): ?><div<?= $x['removed_at'] ? ' class="muted" style="text-decoration:line-through"' : '' ?>><b><?= h(DC_NOTE_KINDS[$x['kind']]) ?></b> · <?= h((string)$x['by_name']) ?> · <?= h(substr((string)$x['created_at'], 0, 10)) ?> — <?= h($x['text']) ?><?= $x['action'] !== '' ? ' <span class="muted">(action: ' . h($x['action']) . ')</span>' : '' ?>
<?php if (!$x['removed_at'] && ((int)$x['by_user'] === $uid || $hr)): ?><form method="post" class="row" style="display:inline-flex"><?= $hid('noteoff', 0, $code) ?><input type="hidden" name="note" value="<?= (int)$x['id'] ?>"><input name="reason" placeholder="Why" required><button class="btn sm ghost">Take off</button></form><?php endif; ?></div><?php endforeach; ?></div>
<div class="card"><h3>Monthly check-ins</h3><?php foreach ($ck as $x): ?><div><?= h($x['ym']) ?> · <b><?= h(DC_CHECK[$x['state']]) ?></b> · <?= h((string)$x['by_name']) ?><?= $x['note'] !== '' ? ' — ' . h($x['note']) : '' ?></div><?php endforeach; ?></div>
<div class="card"><h3>Cases</h3><table><?php foreach (array_reverse($cases) as $c): ?><tr><td><a href="?view=case&id=<?= (int)$c['id'] ?>"><?= h((string)$c['ref']) ?></a></td><td><?= h(DC_STEPS[$c['step']]) ?> · <?= h(DC_SEV[$c['severity']]) ?></td><td><?= h(DC_STATUS[$c['status']]) ?></td>
<td class="muted"><?= isset($lap[(int)$c['id']]) ? ($lap[(int)$c['id']] > date('Y-m-d') ? 'open till ' : 'lapsed ') . h($lap[(int)$c['id']]) : '' ?></td></tr><?php endforeach; ?></table></div>
<?php endif;

elseif ($view === 'case'):
    $c = dc_case_get($db, (int)($_GET['id'] ?? 0));
    if (!$c || !dc_case_visible($db, $u, $c)): ?><div class="card">Not found.</div><?php else:
    $own = strcasecmp($c['emp_code'], $me) === 0; $files = [];
    if (!$own) { $f = $db->prepare('SELECT id, name, note, size FROM dc_file WHERE case_id = ?'); $f->execute([(int)$c['id']]); $files = $f->fetchAll(); }
    $log = $db->prepare('SELECT l.*, u.name FROM dc_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.case_id = ? ORDER BY l.id'); $log->execute([(int)$c['id']]);
    $canHr = $hr && !$own && (int)$c['raised_by'] !== $uid && emp_in_scope($u, $c);
    $now = date('Y-m-d H:i:s'); ?>
<div class="card"><h2><?= h((string)$c['ref']) ?> · <?= h(DC_STEPS[$c['step']]) ?></h2>
<p><?= h((string)$c['name']) ?> · <?= h(DC_CAT[$c['category']]) ?> · <?= h(DC_SEV[$c['severity']]) ?> · happened <?= h((string)$c['inc_date']) ?></p>
<p><b><?= h(DC_STATUS[$c['status']]) ?></b><?= $c['hr_note'] && !$own ? ' — HR: ' . h($c['hr_note']) : '' ?><?= $c['appr_note'] && !$own ? ' — approver: ' . h($c['appr_note']) : '' ?></p>
<p style="white-space:pre-wrap"><?= h($c['what']) ?></p><?= $c['expected'] !== '' ? '<p class="muted">Expected: ' . h($c['expected']) . '</p>' : '' ?>
<a class="btn sm ghost" href="?view=letter&id=<?= (int)$c['id'] ?>" target="_blank">The letter</a></div>
<?php if ($c['reply_at'] || $c['status'] === 'issued'): ?><div class="card"><h3>Reply</h3><?php if ($c['reply_at']): ?><p style="white-space:pre-wrap"><?= h((string)$c['reply']) ?></p><p class="muted"><?= h((string)$c['reply_at']) ?><?= $c['reply_late'] ? ' · after the reply time' : '' ?></p>
<?php else: ?><p class="muted">Reply due by <?= h(date('j M Y H:i', strtotime((string)$c['issued_at']) + (int)$set['reply_hours'] * 3600)) ?>.</p><?php endif; ?>
<?php if ($own && $c['status'] === 'issued'): ?><?php if (!$c['ack_at']): ?><form method="post"><?= $hid('ack', (int)$c['id']) ?><button class="btn sm ghost">I have received this letter</button></form><?php endif; ?>
<?php if (!$c['reply_at']): ?><form method="post"><?= $hid('reply', (int)$c['id']) ?><textarea name="text" rows="4" required placeholder="Your explanation"></textarea><button class="btn sm">Send my reply</button></form><?php endif; endif; ?>
<?php if ($canHr && $c['status'] === 'issued' && !$c['reply_at']): ?><details><summary>Record a reply given on paper</summary><form method="post"><?= $hid('reply', (int)$c['id']) ?><textarea name="text" rows="3" required aria-label="Message"></textarea><button class="btn sm ghost">Record</button></form></details><?php endif; ?></div><?php endif; ?>
<?php if ($own && dc_appeal_open($c, $now, (int)$set['appeal_days'])): ?><form method="post" class="card"><?= $hid('appeal', (int)$c['id']) ?><h3>Appeal</h3><p class="muted">Once, within <?= (int)$set['appeal_days'] ?> days of the letter.</p><textarea name="text" rows="3" required aria-label="Message"></textarea><button class="btn sm">Send appeal</button></form><?php endif; ?>
<?php if ($c['appeal_at']): ?><div class="card"><h3>Appeal</h3><p style="white-space:pre-wrap"><?= h((string)$c['appeal']) ?></p><?= $c['appeal_result'] ? '<p><b>' . ($c['appeal_result'] === 'upheld' ? 'Upheld — the case is withdrawn' : 'Dismissed — the case stands') . '</b> · ' . h($c['appeal_note']) . '</p>' : '' ?>
<?php if (!$c['appeal_result'] && dc_has($u, 'judge_roles') && !$own && (int)$c['raised_by'] !== $uid): ?><form method="post" class="row"><?= $hid('appealdecide', (int)$c['id']) ?><input name="note" placeholder="Reason" required><button class="btn sm" name="upheld" value="1">Uphold (withdraw the case)</button><button class="btn sm ghost" name="upheld" value="0">Dismiss</button></form><?php endif; ?></div><?php endif; ?>
<?php if ($canHr && $c['status'] === 'review_hr'): ?><form method="post" class="card"><?= $hid('hr', (int)$c['id']) ?><h3>HR review</h3>
<div class="grid"><label>Severity <?= $sel(DC_SEV, 'severity', $c['severity']) ?></label><label>Step <?= $sel(DC_STEPS, 'step', $c['step']) ?></label></div>
<label>What happened <textarea name="what" rows="3"><?= h($c['what']) ?></textarea></label><label>Expected <textarea name="expected" rows="2"><?= h($c['expected']) ?></textarea></label><input name="note" placeholder="Note (needed to send back or not issue)">
<div class="row"><button class="btn" name="act" value="issue"><?= in_array($c['severity'], $set['approve_for'], true) ? 'Send for approval' : 'Issue' ?></button><button class="btn ghost" name="act" value="return">Send back to the raiser</button><button class="btn ghost" name="act" value="reject">Do not issue</button></div></form><?php endif; ?>
<?php if ($c['status'] === 'review_appr' && dc_has($u, 'approve_roles') && !$own && (int)$c['raised_by'] !== $uid): ?><form method="post" class="card row"><?= $hid('approve', (int)$c['id']) ?><input name="note" placeholder="Note (needed to send back)"><button class="btn" name="ok" value="1">Approve and issue</button><button class="btn ghost" name="ok" value="0">Send back to HR</button></form><?php endif; ?>
<?php if ($c['status'] === 'returned' && (int)$c['raised_by'] === $uid): ?><form method="post" class="card"><?= $hid('resend', (int)$c['id']) ?><h3>Sent back to you</h3><p><?= h($c['hr_note']) ?></p><textarea name="what" rows="3" aria-label="What happened"><?= h($c['what']) ?></textarea><textarea name="expected" rows="2"><?= h($c['expected']) ?></textarea><button class="btn sm">Send to HR again</button></form><?php endif; ?>
<?php if ($canHr && $c['status'] === 'issued'): ?><form method="post" class="card row"><?= $hid('close', (int)$c['id']) ?><input name="outcome" placeholder="Outcome" required><button class="btn sm">Close the case</button></form><?php endif; ?>
<?php if (!$own): ?><div class="card"><h3>Evidence</h3><p class="muted">Never shown to the person the case is about.</p><?php foreach ($files as $f): ?><div><a href="?view=file&f=<?= h($f['id']) ?>" target="_blank"><?= h($f['name']) ?></a> <span class="muted"><?= h($f['note']) ?></span></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data" class="row"><?= $hid('file', (int)$c['id']) ?><input type="file" name="f" accept="image/*,application/pdf" required aria-label="File"><input name="note" placeholder="What it shows"><button class="btn sm ghost">Add</button></form></div>
<div class="card"><h3>History</h3><?php foreach ($log as $l): ?><div class="muted"><?= h((string)$l['at']) ?> · <?= h((string)$l['name']) ?> · <?= h($l['action']) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php endif;

elseif ($view === 'checkins'): $ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['ym'] ?? '')) ? (string)$_GET['ym'] : date('Y-m'); $due = dc_checkins_due($db, $u, $ym); ?>
<form class="card row"><input type="hidden" name="view" value="checkins"><input type="month" name="ym" value="<?= h($ym) ?>" max="<?= date('Y-m') ?>" aria-label="Month"><button class="btn sm ghost">Show</button></form>
<div class="card"><h2>Check-ins to give · <?= h($ym) ?></h2><?php if (!$due): ?><p class="muted">All done for this month.</p><?php endif; ?>
<?php foreach ($due as $p): ?><form method="post" class="row" style="flex-wrap:wrap"><?= $hid('checkin', 0, $p['code']) ?><input type="hidden" name="ym" value="<?= h($ym) ?>"><input type="hidden" name="back" value="1"><b style="min-width:180px"><?= h($p['name']) ?></b><?= $sel(DC_CHECK, 'state') ?><input name="text" placeholder="Note (needed unless on track)" style="flex:1"><button class="btn sm">Save</button></form><?php endforeach; ?></div>

<?php elseif ($view === 'code'): $cl = $db->query('SELECT * FROM dc_code ORDER BY sort, id')->fetchAll(); $v = dc_code_version_of($cl);
    $acc = $db->prepare('SELECT at FROM dc_code_ack WHERE user_id = ? AND version = ?'); $acc->execute([$uid, $v]); $acc = $acc->fetchColumn(); ?>
<div class="card"><h2>Code of conduct</h2><?php if (!$v): ?><p class="muted">HR has not written it yet.</p><?php endif; ?>
<?php foreach ($cl as $x): if (!(int)$x['active']) continue; ?><h3><?= h($x['no'] . ' · ' . $x['title']) ?></h3><p style="white-space:pre-wrap"><?= h($x['text']) ?></p><?php endforeach; ?>
<?php if ($v && $me !== ''): ?><?php if ($acc): ?><p class="muted">You accepted this version on <?= h((string)$acc) ?>.</p><?php else: ?><form method="post"><?= $hid('accept') ?><button class="btn">I have read and accept the code of conduct</button></form><?php endif; endif; ?></div>
<?php if ($hr): $nq = $db->prepare('SELECT COUNT(*) FROM dc_code_ack WHERE version = ?'); $nq->execute([$v]); $n = $v ? (int)$nq->fetchColumn() : 0; ?>
<div class="card"><h2>Clauses</h2><p class="muted"><?= $n ?> logins accepted the current version. A clause is switched off, never deleted, so letters that cite it stay readable.</p>
<?php foreach (array_merge($cl, [['id' => 0, 'no' => '', 'title' => '', 'text' => '', 'sort' => 10 * (count($cl) + 1), 'active' => 1]]) as $x): ?>
<form method="post" class="card"><?= $hid('clause') ?><input type="hidden" name="cid" value="<?= (int)$x['id'] ?>"><div class="grid"><input name="no" value="<?= h($x['no']) ?>" placeholder="No."><input name="title" value="<?= h($x['title']) ?>" placeholder="Title"><input type="number" name="sort" value="<?= (int)$x['sort'] ?>" aria-label="Order">
<label><input type="checkbox" name="active" value="1"<?= (int)$x['active'] ? ' checked' : '' ?>> In use</label></div><textarea name="text" rows="2" placeholder="Text"><?= h($x['text']) ?></textarea><button class="btn sm<?= $x['id'] ? ' ghost' : '' ?>"><?= $x['id'] ? 'Save' : 'Add clause' ?></button></form><?php endforeach; ?></div><?php endif; ?>

<?php elseif ($view === 'settings' && $isAdmin): $roles = role_labels(); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['hr_roles' => 'HR review and closing', 'approve_roles' => 'Approval of serious matters', 'judge_roles' => 'Deciding appeals', 'see_all_roles' => 'See everyone\'s notes and cases (besides HR)'] as $k => $l): ?>
<h3><?= h($l) ?></h3><div class="row" style="flex-wrap:wrap"><?php foreach ($roles as $code => $nm): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, $set[$k], true) ? ' checked' : '' ?>> <?= h($nm) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<h3>Needs approval</h3><div class="row"><?php foreach (DC_SEV as $k => $l): ?><label><input type="checkbox" name="approve_for[]" value="<?= $k ?>"<?= in_array($k, $set['approve_for'], true) ? ' checked' : '' ?>> <?= h($l) ?></label><?php endforeach; ?></div>
<label><input type="checkbox" name="safety_serious" value="1"<?= $set['safety_serious'] ? ' checked' : '' ?>> A safety matter is always at least serious</label>
<div class="grid"><label>Reply within (hours) <input type="number" name="reply_hours" value="<?= (int)$set['reply_hours'] ?>"></label><label>Appeal within (days) <input type="number" name="appeal_days" value="<?= (int)$set['appeal_days'] ?>"></label>
<label>Minor repeats count for (months) <input type="number" name="repeat_months" value="<?= (int)$set['repeat_months'] ?>"></label><label>Signed by <input name="signatory" value="<?= h($set['signatory']) ?>"></label></div>
<h3>Lapse after (months)</h3><div class="grid"><?php foreach (DC_SEV as $k => $l): ?><label><?= h($l) ?> <input type="number" name="lapse[<?= $k ?>]" value="<?= (int)$set['lapse'][$k] ?>"></label><?php endforeach; ?></div>
<h3>Letter wording</h3><p class="muted">Keep the %s / %d markers: they are the date, the hours and the days.</p><?php foreach ($set['letter'] as $k => $v): ?><label><?= h($k) ?> <textarea name="letter[<?= $k ?>]" rows="2"><?= h($v) ?></textarea></label><?php endforeach; ?>
<button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
