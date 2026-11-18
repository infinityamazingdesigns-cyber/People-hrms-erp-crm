<?php
// Recruitment pipeline — positions (requisitions), candidate board by stage, one candidate, add candidate, search,
// talent pool, settings. Interview feedback for panel members is on panel.php (My interviews).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/recruitment.php';
$u = module_require('recruitment');
$db = pdo();
$set = rc_settings(); $R = rc_rights($u); $uid = (int)$u['id'];
$money = data_allowed($u, 'pay');
$today = date('Y-m-d');
$view = (string)($_GET['view'] ?? 'home');
$isAdmin = has_role($u, 'SUPERADMIN');
$one = function (string $f): ?array { $t = $_FILES[$f]['tmp_name'] ?? ''; return is_string($t) && $t !== '' && (int)($_FILES[$f]['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file($t) ? [(string)file_get_contents($t), (string)$_FILES[$f]['name']] : null; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $to = (string)($_POST['back'] ?? 'index.php');
    if (!preg_match('/^index\.php(\?[A-Za-z0-9=&_%-]*)?(#[a-z0-9]+)?$/', $to)) $to = 'index.php';
    try {
        switch ($a) {
            case 'req': $id = rc_req_save($db, $u, $id, $_POST, !empty($_POST['send'])); flash(!empty($_POST['send']) ? ($set['approval'] ? 'Sent for approval.' : 'Position opened.') : 'Saved.'); $to = 'index.php?view=req&id=' . $id; break;
            case 'decide': rc_req_decide($db, $u, $id, ($_POST['ok'] ?? '') === '1', (string)($_POST['note'] ?? '')); flash('Saved.'); break;
            case 'reqstatus': rc_req_status($db, $u, $id, (string)$_POST['to'], (string)($_POST['note'] ?? '')); flash('Saved.'); break;
            case 'cand':
                $rq = (int)($_POST['req_id'] ?? 0) ?: null;
                [$cid, $new] = rc_cand_add($db, $u, $_POST, $rq, $one('cv'));
                flash($new ? 'Candidate added.' : 'This person was already on file (same mobile or email) — the application was added and blank details filled.');
                $to = 'index.php?view=cand&id=' . $cid; break;
            case 'candedit': rc_cand_edit($db, $u, $id, $_POST); flash('Saved.'); break;
            case 'cv': if (!($f = $one('cv'))) throw new RuntimeException('Pick a file.'); rc_need($u, 'manage'); rc_cv_add($db, $u, $id, $f[0], $f[1]); flash('CV added.'); break;
            case 'remove': rc_cand_remove($db, $u, $id, (string)($_POST['reason'] ?? '')); flash('Switched off.'); $to = 'index.php'; break;
            case 'erase': rc_cand_erase($db, $u, $id, (string)($_POST['reason'] ?? '')); flash('Personal details erased; the history stays.'); $to = 'index.php'; break;
            case 'move': rc_move($db, $u, $id, (string)$_POST['to'], (string)($_POST['note'] ?? '')); flash('Moved.'); break;
            case 'apply': rc_app_add($db, $u, $id, (int)($_POST['req_id'] ?? 0) ?: null); flash('Added to the position.'); break;
            case 'rate': rc_rate($db, $u, $id, (int)$_POST['rating']); flash('Saved.'); break;
            case 'iv': rc_interview_add($db, $u, $id, $_POST); flash('Interview scheduled.'); break;
            case 'ivstatus': rc_interview_status($db, $u, $id, (string)$_POST['to']); flash('Saved.'); break;
            case 'fb': rc_feedback($db, $u, $id, $_POST); flash('Feedback saved.'); break;
            case 'offer': rc_offer_add($db, $u, $id, $_POST); flash('Offer recorded.'); break;
            case 'offerdecide': rc_offer_decide($db, $u, $id, (string)$_POST['to'], (string)($_POST['note'] ?? '')); flash('Saved.'); break;
            case 'onb': $oid = rc_to_onboarding($db, $u, $id); flash('Onboarding checklist #' . $oid . ' started.'); break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = rc_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['recruitment'] ?? ['roles' => module_roles('recruitment')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['recruitment', 1, implode(',', $st['roles'] ?: module_roles('recruitment')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'recruitment'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

if ($view === 'file') {
    if (!$R['manage']) { http_response_code(404); exit('Not found'); }
    $s = $db->prepare('SELECT f.* FROM rc_file f JOIN rc_candidate c ON c.id = f.candidate_id WHERE f.id = ? AND f.size > 0'); $s->execute([(string)($_GET['f'] ?? '')]); $f = $s->fetch();
    if (!$f) { http_response_code(404); exit('Not found'); }
    audit($uid, 'rc_cv_view', '#' . $f['candidate_id']);
    header('Content-Type: ' . $f['type']); header('Content-Disposition: ' . ($f['type'] === 'application/pdf' ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $f['name']) . '"');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox"); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    echo is_resource($f['data']) ? stream_get_contents($f['data']) : $f['data']; exit;
}

$bnames = []; foreach (branches(false) as $b) $bnames[(int)$b['id']] = $b['name'];
$sel = fn(array $o, string $n, string $c, string $extra = '') => '<select name="' . $n . '"' . $extra . '>' . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($o), $o)) . '</select>';
$hidden = fn(string $a, int $id, string $back) => csrf_field() . '<input type="hidden" name="a" value="' . h($a) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . h($back) . '">';
$openReqs = array_values(array_filter(rc_req_list($db, $u), fn($r) => in_array($r['status'], ['open', 'hold'], true)));
$reqOpts = ['' => 'Talent pool (no position)'] + array_combine(array_map(fn($r) => (string)$r['id'], $openReqs), array_map(fn($r) => $r['ref'] . ' · ' . $r['title'], $openReqs));

ui_head('Recruitment', $u, 'recruitment'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'home' ? '' : ' ghost' ?>" href="index.php">Positions</a>
<?php if ($R['raise']): ?> <a class="btn sm<?= $view === 'newreq' ? '' : ' ghost' ?>" href="?view=newreq">Raise a requisition</a><?php endif; ?>
<?php if ($R['manage']): ?> <a class="btn sm<?= $view === 'add' ? '' : ' ghost' ?>" href="?view=add">Add candidate</a> <a class="btn sm<?= $view === 'pool' ? '' : ' ghost' ?>" href="?view=pool">Talent pool</a> <a class="btn sm<?= $view === 'search' ? '' : ' ghost' ?>" href="?view=search">Find</a><?php endif; ?>
 <a class="btn sm ghost" href="panel.php">My interviews</a>
<?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php
$reqForm = function (?array $r) use ($sel, $money, $bnames): void {
    $r ??= ['id' => 0, 'title' => '', 'department' => '', 'branch_id' => null, 'openings' => 1, 'emp_type' => 'permanent', 'reason' => 'new', 'replaces' => '', 'qual_level' => 0, 'disciplines' => '',
            'exp_min' => null, 'exp_max' => null, 'skills' => '', 'titles' => '', 'location' => '', 'ctc_max' => null, 'notice_max' => null, 'hire_by' => null, 'jd' => '', 'weights' => '', 'missing_zero' => 1, 'status' => 'draft'];
    $w = array_replace(RC_WEIGHTS, (array)json_decode((string)$r['weights'], true)); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="req"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<h2><?= $r['id'] ? 'Change requisition' : 'Raise a requisition' ?></h2>
<div class="grid"><label>Position <input name="title" value="<?= h($r['title']) ?>" required maxlength="100"></label><label>Department <input name="department" value="<?= h($r['department']) ?>"></label>
<label>Branch <?= $sel(['' => '—'] + $bnames, 'branch_id', (string)$r['branch_id']) ?></label><label>Openings <input type="number" name="openings" min="1" max="500" value="<?= (int)$r['openings'] ?>"></label>
<label>Type <?= $sel(RC_EMP_TYPES, 'emp_type', $r['emp_type']) ?></label><label>Reason <?= $sel(['new' => 'New position', 'replacement' => 'Replacement'], 'reason', $r['reason']) ?></label>
<label>Replaces (name) <input name="replaces" value="<?= h($r['replaces']) ?>"></label><label>Hire by <input type="date" name="hire_by" value="<?= h((string)$r['hire_by']) ?>"></label></div>
<h3>What it needs (used for the ATS match)</h3>
<div class="grid"><label>Qualification <?= $sel([0 => '—'] + RC_LEVELS, 'qual_level', (string)$r['qual_level']) ?></label><label>Disciplines <input name="disciplines" value="<?= h($r['disciplines']) ?>" placeholder="mechanical, electrical"></label>
<label>Experience from (yrs) <input name="exp_min" value="<?= h((string)($r['exp_min'] ?? '')) ?>"></label><label>to (yrs) <input name="exp_max" value="<?= h((string)($r['exp_max'] ?? '')) ?>"></label>
<label>Titles that fit <input name="titles" value="<?= h($r['titles']) ?>"></label><label>Location <input name="location" value="<?= h($r['location']) ?>"></label>
<label>Notice up to (days) <input name="notice_max" value="<?= h((string)($r['notice_max'] ?? '')) ?>"></label>
<?php if ($money): ?><label>Pay up to (per month) <input name="ctc_max" value="<?= h((string)($r['ctc_max'] ?? '')) ?>"></label><?php endif; ?></div>
<label>Key skills (comma separated) <input name="skills" value="<?= h($r['skills']) ?>"></label>
<label>Job description <textarea name="jd" rows="5"><?= h($r['jd']) ?></textarea></label>
<details><summary>Weights of the match</summary><div class="grid"><?php foreach (RC_WLABEL as $k => $l): ?><label><?= h($l) ?> <input type="number" name="w_<?= $k ?>" min="0" max="100" value="<?= (int)$w[$k] ?>"></label><?php endforeach; ?></div>
<label><input type="checkbox" name="missing_zero" value="1"<?= (int)$r['missing_zero'] ? ' checked' : '' ?>> A requirement the application does not show counts as 0</label></details>
<div class="row"><button class="btn ghost">Save</button><?php if (in_array($r['status'], ['draft', 'rejected'], true)): ?> <button class="btn" name="send" value="1"><?= rc_settings()['approval'] ? 'Save and send for approval' : 'Save and open' ?></button><?php endif; ?></div>
</form>
<?php };

if ($view === 'home'):
    $ov = rc_overview($db, $u); $reqs = rc_req_list($db, $u); if ($R['manage'] || $R['approve']): ?>
<div class="grid"><div class="card"><b><?= $ov['open'] ?></b><div class="muted">Open positions</div></div><div class="card"><b><?= $ov['approval'] ?></b><div class="muted">Waiting for approval</div></div>
<div class="card"><b><?= $ov['stage']['new'] + $ov['stage']['screening'] ?></b><div class="muted">To screen</div></div><div class="card"><b><?= $ov['interviews'] ?></b><div class="muted">Interviews next 7 days</div></div>
<div class="card"><b><?= $ov['offers'] ?></b><div class="muted">Offers out</div></div></div><?php endif; ?>
<div class="card"><h2>Positions</h2><table><tr><th>Ref</th><th>Position</th><th>Branch</th><th>Openings</th><th>In process</th><th>Joined</th><th>Status</th><th>Hire by</th></tr>
<?php foreach ($reqs as $r): ?><tr><td><a href="?view=req&id=<?= (int)$r['id'] ?>"><?= h($r['ref']) ?></a></td><td><?= h($r['title']) ?><div class="muted"><?= h($r['department']) ?></div></td>
<td><?= h($bnames[(int)$r['branch_id']] ?? '') ?></td><td><?= (int)$r['openings'] ?></td><td><?= (int)$r['active'] - (int)$r['joined'] ?></td><td><?= (int)$r['joined'] ?></td><td><?= h(RC_REQ_STATUS[$r['status']]) ?></td><td><?= h((string)$r['hire_by']) ?></td></tr><?php endforeach; ?>
<?php if (!$reqs): ?><tr><td colspan="8" class="muted">No requisitions yet.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'newreq' && $R['raise']): $reqForm(null);

elseif ($view === 'req'):
    $r = rc_req_get($db, (int)($_GET['id'] ?? 0));
    $mine = $r && (int)$r['raised_by'] === $uid;
    if (!$r || (!$mine && !$R['manage'] && !$R['approve']) || ($r['branch_id'] !== null && !$mine && !emp_in_scope($u, $r))): ?><div class="card">Not found.</div><?php else:
    $back = 'index.php?view=req&id=' . (int)$r['id']; ?>
<div class="card"><h2><?= h($r['ref'] . ' · ' . $r['title']) ?></h2>
<p><?= h(implode(' · ', array_filter([$r['department'], $bnames[(int)$r['branch_id']] ?? '', RC_EMP_TYPES[$r['emp_type']] ?? '', (int)$r['openings'] . ' opening' . ((int)$r['openings'] > 1 ? 's' : ''), $r['reason'] === 'replacement' ? 'Replaces ' . $r['replaces'] : 'New position']))) ?></p>
<p><b><?= h(RC_REQ_STATUS[$r['status']]) ?></b><?= $r['approve_note'] !== '' ? ' — ' . h($r['approve_note']) : '' ?><?= $r['status_note'] !== '' ? ' — ' . h($r['status_note']) : '' ?></p>
<?php if ($r['jd'] !== ''): ?><details><summary>Job description</summary><p style="white-space:pre-wrap"><?= h($r['jd']) ?></p></details><?php endif; ?>
<?php if ($r['status'] === 'approval' && $R['approve'] && !$mine): ?>
<form method="post" class="row"><?= $hidden('decide', (int)$r['id'], $back) ?><input name="note" placeholder="Note (needed to turn down)"><button class="btn" name="ok" value="1">Approve</button><button class="btn ghost" name="ok" value="0">Not approved</button></form><?php endif; ?>
<?php if ($R['manage'] && in_array($r['status'], ['open', 'hold', 'filled', 'closed', 'draft'], true)): ?>
<form method="post" class="row"><?= $hidden('reqstatus', (int)$r['id'], $back) ?><?= $sel(array_intersect_key(RC_REQ_STATUS, array_flip(['open' => ['hold', 'filled', 'closed'], 'hold' => ['open', 'closed'], 'filled' => ['open'], 'closed' => ['open'], 'draft' => ['closed']][$r['status']])), 'to', '') ?><input name="note" placeholder="Why (for hold / close)"><button class="btn sm ghost">Change status</button></form><?php endif; ?>
</div>
<?php if (($mine || $R['manage']) && in_array($r['status'], ['draft', 'rejected', 'open', 'hold'], true)): ?><details><summary>Change the requisition</summary><?php $reqForm($r); ?></details><?php endif; ?>
<?php if ($R['manage'] && in_array($r['status'], ['open', 'hold', 'filled', 'closed'], true)):
    $board = rc_board($db, (int)$r['id'], $today);
    $sort = ($_GET['sort'] ?? '') === 'ats';
    if ($sort) usort($board, fn($a, $b) => ($b['ats']['pct'] ?? -1) <=> ($a['ats']['pct'] ?? -1)); ?>
<div class="row"><a class="btn sm" href="?view=add&req=<?= (int)$r['id'] ?>">Add candidate</a> <a class="btn sm ghost" href="<?= h($back . ($sort ? '' : '&sort=ats')) ?>"><?= $sort ? 'Newest first' : '★ Sort by ATS match' ?></a></div>
<div style="display:flex;gap:10px;overflow-x:auto;align-items:flex-start">
<?php foreach (RC_STAGES as $sk => $sl): $col = array_filter($board, fn($x) => $x['stage'] === $sk); if (!$col && in_array($sk, ['hold', 'withdrawn'], true)) continue; ?>
<div class="card" style="min-width:220px;flex:1"><h3><?= h($sl) ?> <span class="muted"><?= count($col) ?></span></h3>
<?php foreach ($col as $x): ?><div style="border-top:1px solid var(--line,#ddd);padding:6px 0"><a href="?view=cand&id=<?= (int)$x['id'] ?>"><b><?= h($x['name']) ?></b></a>
<div class="muted"><?= h(implode(' · ', array_filter([$x['cur_title'], $x['total_exp'] !== null ? (float)$x['total_exp'] . ' yrs' : '', $x['city']]))) ?></div>
<?php if ($x['ats']): ?><div title="<?= h(implode(' · ', array_map(fn($rw) => $rw['label'] . ': ' . $rw['d'], array_filter($x['ats']['rows'], fn($rw) => $rw['on'])))) ?>"><?= rc_stars($x['ats']['stars']) ?> <?= $x['ats']['pct'] !== null ? (int)$x['ats']['pct'] . '%' : '' ?><?= $x['ats']['missing'] ? ' <span class="muted">· ' . count($x['ats']['missing']) . ' missing</span>' : '' ?></div><?php endif; ?>
</div><?php endforeach; ?></div>
<?php endforeach; ?></div>
<?php endif; endif;

elseif ($view === 'add' && $R['manage']): $rq = (string)(int)($_GET['req'] ?? 0); ?>
<form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="cand"><h2>Add candidate</h2>
<p class="muted">Someone already on file with the same mobile or email is not added twice — the application goes on their record.</p>
<div class="grid"><label>Position <?= $sel($reqOpts, 'req_id', $rq !== '0' ? $rq : '') ?></label><label>Name <input name="name" required maxlength="120"></label>
<label>Mobile <input name="phone"></label><label>Email <input type="email" name="email"></label><label>City <input name="city"></label>
<label>Relocate <?= $sel(['' => '—', 'yes' => 'Yes', 'no' => 'No', 'travel' => 'Can travel'], 'relocate', '') ?></label>
<label>Current / last title <input name="cur_title"></label><label>Company <input name="cur_company"></label>
<label>Total experience (yrs) <input name="total_exp"></label><label>Relevant (yrs) <input name="rel_exp"></label><label>Qualification <input name="qualification"></label>
<label>Notice (days) <input name="notice_days"></label><label>Source <?= $sel(array_combine(RC_SOURCES, RC_SOURCES), 'source', 'Other') ?></label>
<?php if ($money): ?><label>Current pay (per month) <input name="cur_ctc"></label><label>Expected (per month) <input name="exp_ctc"></label><?php endif; ?></div>
<label>Key skills <input name="skills"></label><label>Notes <textarea name="notes" rows="3"></textarea></label>
<label>CV (PDF, Word, ODT, RTF, text; 5 MB) <input type="file" name="cv"></label>
<details><summary>Paste the CV text (used for the match)</summary><textarea name="cv_text" rows="6"></textarea></details>
<label><input type="checkbox" name="consent" value="1"> The candidate agreed to their details being kept for recruitment</label>
<button class="btn">Add</button></form>

<?php elseif ($view === 'cand' && $R['manage']):
    $c = rc_cand_get($db, (int)($_GET['id'] ?? 0));
    if (!$c || $c['removed_at'] !== null): ?><div class="card">Not found.</div><?php else:
    $back = 'index.php?view=cand&id=' . (int)$c['id'];
    $apps = $db->prepare('SELECT a.*, r.title AS req_title, r.ref, r.branch_id FROM rc_application a LEFT JOIN rc_requisition r ON r.id = a.req_id WHERE a.candidate_id = ? ORDER BY a.id DESC'); $apps->execute([(int)$c['id']]); $apps = $apps->fetchAll();
    $files = $db->prepare('SELECT id, name, size, created_at FROM rc_file WHERE candidate_id = ? AND size > 0 ORDER BY created_at DESC'); $files->execute([(int)$c['id']]); $files = $files->fetchAll();
    $users = $db->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    $log = $db->prepare("SELECT l.*, u.name AS who FROM rc_log l LEFT JOIN users u ON u.id = l.user_id WHERE (l.kind = 'cand' AND l.ref_id = ?) OR (l.kind = 'app' AND l.ref_id IN (SELECT id FROM rc_application WHERE candidate_id = ?)) ORDER BY l.id DESC LIMIT 40"); $log->execute([(int)$c['id'], (int)$c['id']]); ?>
<div class="card"><h2><?= h($c['name']) ?></h2><p><?= h(implode(' · ', array_filter([$c['cur_title'], $c['cur_company'], $c['total_exp'] !== null ? (float)$c['total_exp'] . ' yrs' : '', $c['qualification'], $c['city'], $c['notice_days'] !== null ? (int)$c['notice_days'] . ' days notice' : '']))) ?></p>
<p><?= h(implode(' · ', array_filter([$c['phone'], $c['email'], 'Source: ' . $c['source'], $c['consent'] ? 'Consent given' : 'No consent recorded']))) ?><?php if ($money && ($c['cur_ctc'] !== null || $c['exp_ctc'] !== null)): ?> · Pay now <?= h((string)$c['cur_ctc']) ?> → expects <?= h((string)$c['exp_ctc']) ?><?php endif; ?></p>
<?php foreach ($files as $f): ?><a href="?view=file&f=<?= h($f['id']) ?>"><?= h($f['name']) ?></a> <span class="muted"><?= round((int)$f['size'] / 1024) ?> KB</span> <?php endforeach; ?>
<form method="post" enctype="multipart/form-data" class="row"><?= $hidden('cv', (int)$c['id'], $back) ?><input type="file" name="cv"><button class="btn sm ghost">Add CV</button></form></div>

<?php foreach ($apps as $a): $ab = $back . '#a' . (int)$a['id'];
    $r = $a['req_id'] ? rc_req_get($db, (int)$a['req_id']) : null; $ats = $r ? rc_ats($c, $r, $today) : null;
    $ivs = $db->prepare('SELECT * FROM rc_interview WHERE application_id = ? ORDER BY at'); $ivs->execute([(int)$a['id']]); $ivs = $ivs->fetchAll();
    $ofs = $db->prepare('SELECT * FROM rc_offer WHERE application_id = ? ORDER BY id'); $ofs->execute([(int)$a['id']]); $ofs = $ofs->fetchAll(); ?>
<div class="card" id="a<?= (int)$a['id'] ?>"><h3><?= h($a['req_id'] ? $a['ref'] . ' · ' . $a['req_title'] : 'Talent pool') ?> — <?= h(RC_STAGES[$a['stage']]) ?></h3>
<?php if ($a['stage_note'] !== ''): ?><p class="muted"><?= h($a['stage_note']) ?></p><?php endif; ?>
<?php if ($ats): ?><details><summary>ATS match <?= rc_stars($ats['stars']) ?> <?= $ats['pct'] !== null ? (int)$ats['pct'] . '%' : 'not enough to compare' ?><?= $ats['low'] ? ' · few checks' : '' ?></summary>
<table><?php foreach ($ats['rows'] as $rw): if (!$rw['w']) continue; ?><tr><td><?= h($rw['label']) ?></td><td><?= $rw['on'] ? (int)round((float)$rw['v'] * 100) . '%' : '—' ?></td><td class="muted"><?= h($rw['d'] ?: 'Not checked') ?></td><td class="muted">weight <?= (int)$rw['w'] ?></td></tr><?php endforeach; ?></table>
<?php if ($ats['miss']): ?><p class="muted">Skills not found: <?= h(implode(', ', $ats['miss'])) ?></p><?php endif; ?><p class="muted">A screening aid only — it never moves anyone.</p></details><?php endif; ?>
<div class="row" style="flex-wrap:wrap">
<form method="post" class="row"><?= $hidden('move', (int)$a['id'], $ab) ?><?= $sel(array_diff_key(RC_STAGES, [$a['stage'] => 1, 'offer' => 1]), 'to', '') ?><input name="note" placeholder="Note (needed to reject / hold / reopen)"><button class="btn sm">Move</button></form>
<form method="post" class="row"><?= $hidden('rate', (int)$a['id'], $ab) ?><?= $sel(['' => 'HR rating'] + array_combine(range(1, 5), array_map(fn($n) => str_repeat('★', $n), range(1, 5))), 'rating', (string)$a['rating']) ?><button class="btn sm ghost">Rate</button></form>
<?php if ($a['stage'] === 'accepted' && module_on('onboarding')): ?><form method="post"><?= $hidden('onb', (int)$a['id'], $ab) ?><button class="btn sm"><?= $a['onb_id'] ? 'Onboarding #' . (int)$a['onb_id'] . ' started' : 'Start onboarding' ?></button></form><?php endif; ?></div>
<h4>Interviews</h4>
<?php foreach ($ivs as $i): $fb = $db->prepare('SELECT f.*, u.name FROM rc_feedback f JOIN users u ON u.id = f.user_id WHERE f.interview_id = ?'); $fb->execute([(int)$i['id']]); ?>
<div>Round <?= (int)$i['round'] ?> · <?= h(date('D j M Y, H:i', strtotime((string)$i['at']))) ?> · <?= h(RC_MODES[$i['mode']]) ?><?= $i['place'] !== '' ? ' · ' . h($i['place']) : '' ?> · <b><?= h($i['status']) ?></b>
<span class="muted">Panel: <?= h(implode(', ', array_map(fn($p) => $users[(int)$p] ?? '#' . $p, array_filter(explode(',', (string)$i['panel']))))) ?></span>
<form method="post" class="row" style="display:inline-flex"><?= $hidden('ivstatus', (int)$i['id'], $ab) ?><?= $sel(['done' => 'Done', 'noshow' => 'Did not come', 'cancelled' => 'Cancelled', 'scheduled' => 'Scheduled'], 'to', (string)$i['status']) ?><button class="btn sm ghost">Set</button></form>
<?php foreach ($fb as $f): ?><div class="muted"><?= h($f['name']) ?>: <?= str_repeat('★', (int)$f['rating']) ?> · <?= h(RC_VERDICTS[$f['verdict']]) ?><?= $f['comments'] !== '' ? ' — ' . h($f['comments']) : '' ?></div><?php endforeach; ?></div>
<?php endforeach; ?>
<?php if (!in_array($a['stage'], RC_END, true)): ?>
<details><summary>Schedule an interview</summary><form method="post"><?= $hidden('iv', (int)$a['id'], $ab) ?><div class="grid"><input type="datetime-local" name="at" required><?= $sel(RC_MODES, 'mode', 'person') ?><input name="place" placeholder="Place or https:// link">
<select name="panel[]" multiple size="4"><?php foreach ($users as $id => $n): ?><option value="<?= (int)$id ?>"><?= h($n) ?></option><?php endforeach; ?></select></div><button class="btn sm">Schedule</button></form></details><?php endif; ?>
<h4>Offer</h4>
<?php foreach ($ofs as $o): ?><div><?= h($o['status']) ?><?= $money && $o['ctc'] !== null ? ' · ' . h((string)$o['ctc']) . ' per month' : '' ?><?= $o['join_date'] ? ' · joining ' . h((string)$o['join_date']) : '' ?><?= $o['valid_till'] ? ' · answer by ' . h((string)$o['valid_till']) : '' ?><?= $o['note'] !== '' ? ' — ' . h($o['note']) : '' ?>
<?php if ($o['status'] === 'made'): ?><form method="post" class="row" style="display:inline-flex"><?= $hidden('offerdecide', (int)$o['id'], $ab) ?><?= $sel(['accepted' => 'Accepted', 'declined' => 'Declined by the candidate', 'withdrawn' => 'Withdrawn by us'], 'to', 'accepted') ?><input name="note" placeholder="Note (needed unless accepted)"><button class="btn sm">Save</button></form><?php endif; ?></div><?php endforeach; ?>
<?php if (!in_array($a['stage'], array_merge(RC_END, ['offer', 'accepted']), true)): ?>
<details><summary>Record an offer</summary><form method="post"><?= $hidden('offer', (int)$a['id'], $ab) ?><div class="grid"><?php if ($money): ?><label>Pay (per month) <input name="ctc"></label><?php endif; ?><label>Joining <input type="date" name="join_date"></label><label>Answer by <input type="date" name="valid_till"></label><input name="note" placeholder="Note"></div><button class="btn sm">Record offer</button></form></details><?php endif; ?>
</div>
<?php endforeach; ?>
<form method="post" class="card row"><?= $hidden('apply', (int)$c['id'], $back) ?><?= $sel($reqOpts, 'req_id', '') ?><button class="btn sm ghost">Put on another position</button></form>
<details class="card"><summary>Change details</summary><form method="post"><?= $hidden('candedit', (int)$c['id'], $back) ?>
<div class="grid"><?php foreach (['name' => 'Name', 'phone' => 'Mobile', 'email' => 'Email', 'city' => 'City', 'cur_title' => 'Title', 'cur_company' => 'Company', 'total_exp' => 'Total exp', 'rel_exp' => 'Relevant exp', 'qualification' => 'Qualification', 'notice_days' => 'Notice (days)'] as $k => $l): ?>
<label><?= h($l) ?> <input name="<?= $k ?>" value="<?= h((string)$c[$k]) ?>"></label><?php endforeach; ?>
<label>Relocate <?= $sel(['' => '—', 'yes' => 'Yes', 'no' => 'No', 'travel' => 'Can travel'], 'relocate', (string)$c['relocate']) ?></label><label>Source <?= $sel(array_combine(RC_SOURCES, RC_SOURCES), 'source', (string)$c['source']) ?></label>
<?php if ($money): ?><label>Current pay <input name="cur_ctc" value="<?= h((string)$c['cur_ctc']) ?>"></label><label>Expected <input name="exp_ctc" value="<?= h((string)$c['exp_ctc']) ?>"></label><?php endif; ?></div>
<label>Key skills <input name="skills" value="<?= h($c['skills']) ?>"></label><label>Notes <textarea name="notes" rows="3"><?= h($c['notes']) ?></textarea></label>
<label>CV text <textarea name="cv_text" rows="5"><?= h((string)$c['cv_text']) ?></textarea></label><label><input type="checkbox" name="consent" value="1"<?= $c['consent'] ? ' checked' : '' ?>> Consent given</label>
<button class="btn sm">Save</button></form></details>
<details class="card"><summary>History</summary><?php foreach ($log as $l): ?><div class="muted"><?= h($l['created_at']) ?> · <?= h((string)$l['who']) ?> · <?= h($l['action']) ?><?= $l['detail'] !== '' ? ' (' . h($l['detail']) . ')' : '' ?></div><?php endforeach; ?></details>
<details class="card"><summary>Switch off or erase</summary>
<form method="post" class="row"><?= $hidden('remove', (int)$c['id'], $back) ?><input name="reason" placeholder="Why" required><button class="btn sm ghost">Switch off</button></form>
<?php if (has_role($u, 'SUPERADMIN', 'HRMANAGER')): ?><form method="post" class="row" onsubmit="return confirm('Erase this person\'s details and CV for good?')"><?= $hidden('erase', (int)$c['id'], $back) ?><input name="reason" placeholder="The person's request" required><button class="btn sm ghost">Erase on request</button></form><?php endif; ?></details>
<?php endif;

elseif ($view === 'pool' && $R['manage']): $board = rc_board($db, null, $today); ?>
<div class="card"><h2>Talent pool</h2><table><tr><th>Name</th><th>Title</th><th>Experience</th><th>City</th><th>Stage</th><th>Added</th></tr>
<?php foreach ($board as $x): ?><tr><td><a href="?view=cand&id=<?= (int)$x['id'] ?>"><?= h($x['name']) ?></a></td><td><?= h($x['cur_title']) ?></td><td><?= $x['total_exp'] !== null ? (float)$x['total_exp'] . ' yrs' : '' ?></td><td><?= h($x['city']) ?></td><td><?= h(RC_STAGES[$x['stage']]) ?></td><td><?= h(substr((string)$x['created_at'], 0, 10)) ?></td></tr><?php endforeach; ?>
<?php if (!$board): ?><tr><td colspan="6" class="muted">Nobody in the pool.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'search' && $R['manage']): $q = (string)($_GET['q'] ?? ''); $rows = rc_search($db, $q); ?>
<form class="card row"><input type="hidden" name="view" value="search"><input name="q" value="<?= h($q) ?>" placeholder="Name, mobile, email, skill or title"><button class="btn sm">Find</button></form>
<?php if ($q !== ''): ?><div class="card"><table><tr><th>Name</th><th>Title</th><th>Applications</th></tr><?php foreach ($rows as $x): ?><tr><td><a href="?view=cand&id=<?= (int)$x['id'] ?>"><?= h($x['name']) ?></a></td><td><?= h($x['cur_title']) ?></td><td class="muted"><?= h((string)$x['apps']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="3" class="muted">No one found.</td></tr><?php endif; ?></table></div><?php endif; ?>

<?php elseif ($view === 'settings' && $isAdmin): $roles = role_labels(); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['manage_roles' => 'Who runs the pipeline (candidates, interviews, offers)', 'approve_roles' => 'Who approves requisitions', 'raise_roles' => 'Who raises requisitions'] as $k => $l): ?>
<h3><?= h($l) ?></h3><div class="row" style="flex-wrap:wrap"><?php foreach ($roles as $code => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div>
<?php endforeach; ?>
<label><input type="checkbox" name="approval" value="1"<?= $set['approval'] ? ' checked' : '' ?>> A requisition needs approval before it opens</label>
<label>Reference prefix <input name="ref_prefix" value="<?= h($set['ref_prefix']) ?>" maxlength="6"></label>
<p class="muted">A role raising or approving requisitions also needs the module on Admin → Modules.</p>
<button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
