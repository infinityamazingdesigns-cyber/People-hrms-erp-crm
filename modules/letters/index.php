<?php
// Letter desk — make letters from templates, approval, issue, answers; requests; templates; signatories.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/letters.php';
if (is_file(WN_ROOT . '/lib/probation.php')) require_once WN_ROOT . '/lib/probation.php';
$u = module_require('letters');
$db = pdo();
$isHr = let_is_hr($u);
$canSet = has_role($u, 'SUPERADMIN', 'HRMANAGER');
$view = (string)($_GET['view'] ?? '');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
let_housekeeping($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $to = $id ? 'index.php?id=' . $id : 'index.php';
    try {
        switch ($a) {
            case 'save': $id = let_save($db, $u, $id ?: null, $_POST); $to = 'index.php?id=' . $id; flash('Saved.'); break;
            case 'ask': let_ask_approval($db, $u, $id); flash('Sent for approval.'); break;
            case 'approve': case 'return': let_decide($db, $u, $id, $a === 'approve', (string)($_POST['note'] ?? '')); flash($a === 'approve' ? 'Approved.' : 'Sent back.'); break;
            case 'issue':
                $tok = let_issue($db, $u, $id);
                if ($tok) $_SESSION['let_link'][$id] = $tok;
                flash('Issued.' . ($tok ? ' Copy the answer link below — it is shown only now.' : ''));
                break;
            case 'link': $_SESSION['let_link'][$id] = let_new_link($db, $u, $id); flash('New link made; the old one stopped working.'); break;
            case 'withdraw': let_withdraw($db, $u, $id, (string)($_POST['reason'] ?? '')); flash('Withdrawn.'); break;
            case 'reqclose': let_request_close($db, $u, (int)$_POST['req'], (string)($_POST['note'] ?? '')); flash('Request closed.'); $to = 'index.php?view=requests'; break;
            case 'tpl': let_template_save($db, $u, (int)($_POST['tid'] ?? 0) ?: null, $_POST); flash('Template saved.'); $to = 'index.php?view=templates'; break;
            case 'tploff':
                if (!$canSet || trim((string)($_POST['reason'] ?? '')) === '') throw new RuntimeException('Give a reason.');
                $db->prepare('UPDATE letter_template SET active = 0, off_reason = ? WHERE id = ?')->execute([mb_substr(trim((string)$_POST['reason']), 0, 200), (int)$_POST['tid']]);
                flash('Switched off.'); $to = 'index.php?view=templates'; break;
            case 'signer': let_signer_save($db, $u, $_POST); flash('Signatory saved.'); $to = 'index.php?view=signers'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

$tpls = let_templates($db);
$waiting = let_waiting($db, $u);
$reqs = $isHr ? let_requests_open($db, $u) : [];
$tabs = ['' => 'Letters'] + ($waiting ? ['approve' => 'To approve · ' . count($waiting)] : []) + ($isHr ? ['requests' => 'Requests · ' . count($reqs)] : [])
      + ($canSet ? ['templates' => 'Templates', 'signers' => 'Signatories'] : []);
$base = wn_base();
ui_head('Letter desk', $u, 'letters');
$st = fn($l) => '<span class="pill ' . (in_array($l['status'], ['issued', 'approved'], true) ? 'on' : (in_array($l['status'], ['withdrawn', 'lapsed'], true) ? 'off' : 'port')) . '">' . h(LET_STATUS[$l['status']])
    . ($l['response'] ? ' · ' . h(LET_ANSWER[$l['resp']][$l['response']] ?? '') : '') . '</span>';
?>
<nav class="row"><?php foreach ($tabs as $k => $l): ?><a class="btn sm<?= $view === $k && !$id ? '' : ' ghost' ?>" href="?view=<?= h($k) ?>"><?= h($l) ?></a><?php endforeach; ?></nav>

<?php if ($id && ($l = let_get($db, $id)) && ($isHr || let_can_approve($u, $l))):
    $t = let_template($db, (int)$l['template_id']); $vars = let_vars($db, $l); $miss = let_missing($t['body'], $vars);
    $typed = json_decode((string)$l['fields'], true) ?: []; $open = in_array($l['status'], ['draft', 'approval', 'approved'], true);
    $log = $db->prepare('SELECT g.*, u.name FROM letter_log g LEFT JOIN users u ON u.id = g.by_user WHERE g.letter_id = ? ORDER BY g.id'); $log->execute([$id]); ?>
<p><a href="index.php">‹ All letters</a></p>
<section class="card"><h2><?= h($t['name'] . ' · ' . $l['person']) ?> <?= $st($l) ?></h2>
  <p class="muted"><?= $l['ref'] ? h($l['ref']) . ' · ' : '' ?>Signs: <?= h(role_labels()[$l['signer_role']] ?? $l['signer_role']) ?><?= $l['signer_name'] ? ' (' . h($l['signer_name']) . ')' : '' ?><?= $t['needs_approval'] ? ' · needs approval' : '' ?><?= $l['accept_by'] ? ' · accept by ' . h(date('j M Y', strtotime($l['accept_by']))) : '' ?></p>
  <?php if ($miss && $open): ?><p class="note">Still to fill in: <?= h(implode(', ', $miss)) ?></p><?php endif; ?>
  <?php if (!empty($_SESSION['let_link'][$id])): $link = rtrim((is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''), '/') . $base . 'modules/letters/respond.php?t=' . $_SESSION['let_link'][$id]; unset($_SESSION['let_link'][$id]); ?>
    <p class="note">Answer link (copy now, valid <?= LET_LINK_DAYS ?> days): <input readonly value="<?= h($link) ?>" onclick="this.select()" style="width:100%"></p><?php endif; ?>
  <p class="row"><a class="btn sm ghost" href="print.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= $l['status'] === 'issued' ? 'Open / print' : 'Preview' ?></a>
  <?php if ($isHr && $l['status'] === 'draft' && $t['needs_approval']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm" name="a" value="ask">Send for approval</button></form><?php endif; ?>
  <?php if ($isHr && !let_own($u, $l) && (($t['needs_approval'] && $l['status'] === 'approved') || (!$t['needs_approval'] && $l['status'] === 'draft'))): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm" name="a" value="issue">Issue</button></form><?php endif; ?>
  <?php if ($isHr && $l['status'] === 'issued' && $l['resp'] !== 'none' && !$l['response']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm ghost" name="a" value="link">New answer link</button></form><?php endif; ?></p>
  <?php if ($l['response']): ?><p><b><?= h(LET_ANSWER[$l['resp']][$l['response']]) ?></b> by <?= h($l['resp_name']) ?> on <?= h(date('j M Y H:i', strtotime($l['resp_at']))) ?></p><?php endif; ?>
  <?php if (let_can_approve($u, $l)): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input name="note" placeholder="Note (needed to send back)"><button class="btn sm" name="a" value="approve">Approve</button><button class="btn sm ghost" name="a" value="return">Send back</button></form><?php endif; ?>
</section>
<?php if ($isHr && $open): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= $id ?>"><h2>Details</h2>
  <?php if ($l['emp_code'] === ''): ?><div class="grid"><label>Name <input name="person" value="<?= h($l['person']) ?>" required></label><label>Email <input type="email" name="email" value="<?= h($l['email']) ?>"></label></div><?php else: ?><input type="hidden" name="emp_code" value="<?= h($l['emp_code']) ?>"><?php endif; ?>
  <div class="grid"><?php foreach (let_fields($t['body']) as $f): if (in_array($f, ['ref', 'accept_by'], true)) continue; $auto = !isset($typed[$f]) && isset($vars[$f]); ?>
    <label><?= h(str_replace('_', ' ', ucfirst($f))) ?> <input name="fields[<?= h($f) ?>]" value="<?= h($typed[$f] ?? '') ?>" placeholder="<?= h($auto ? $vars[$f] : '') ?>" maxlength="500"></label><?php endforeach; ?>
    <?php if ($l['resp'] === 'accept'): ?><label>Accept by <input type="date" name="accept_by" value="<?= h((string)$l['accept_by']) ?>"></label><?php endif; ?></div>
  <p class="muted">Grey values come from the employee record; type to replace them. Saving an approved letter sends it back to draft.</p><button class="btn sm">Save</button></form>
<?php endif; ?>
<?php if ($isHr && !in_array($l['status'], ['withdrawn', 'lapsed'], true)): ?><form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input name="reason" placeholder="Reason" required><button class="btn sm ghost" name="a" value="withdraw">Withdraw this letter</button></form><?php endif; ?>
<section class="card"><h2>History</h2><table><tbody><?php foreach ($log as $g): ?><tr><td><?= h(date('j M Y H:i', strtotime($g['at']))) ?></td><td><?= h($g['text']) ?></td><td><?= h((string)$g['name']) ?></td></tr><?php endforeach; ?></tbody></table></section>

<?php elseif ($view === 'approve'): ?>
<section class="card"><h2>Letters to approve</h2><table><tbody><?php foreach ($waiting as $l): ?><tr><td><?= h($l['tname']) ?></td><td><?= h($l['person']) ?></td><td><a href="?id=<?= (int)$l['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></section>

<?php elseif ($view === 'requests' && $isHr): ?>
<section class="card"><h2>Requests from employees</h2><?php if (!$reqs): ?><p class="muted">None open.</p><?php endif; ?><table><tbody>
<?php foreach ($reqs as $r): ?><tr><td><?= h(($r['name'] ?? '') . ' · ' . $r['emp_code']) ?></td><td><?= h($r['tname']) ?></td><td><?= h($r['purpose']) ?></td><td><?= h(date('j M', strtotime($r['created_at']))) ?></td>
  <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="template_id" value="<?= (int)$r['tid'] ?>"><input type="hidden" name="emp_code" value="<?= h($r['emp_code']) ?>"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="fields[purpose]" value="<?= h($r['purpose']) ?>"><button class="btn sm">Make the letter</button></form></td>
  <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="reqclose"><input type="hidden" name="req" value="<?= (int)$r['id'] ?>"><input name="note" placeholder="Why not" required><button class="btn sm ghost">Close</button></form></td></tr>
<?php endforeach; ?></tbody></table></section>

<?php elseif ($view === 'templates' && $canSet):
    $edit = (int)($_GET['tid'] ?? 0) ? let_template($db, (int)$_GET['tid']) : null; ?>
<section class="card"><h2>Templates</h2><table><tbody><?php foreach (let_templates($db, false) as $t): ?><tr><td><?= h($t['code']) ?></td><td><?= h($t['name']) ?></td><td><small><?= h(LET_RESP[$t['resp']] . ' · ' . (role_labels()[$t['signer_role']] ?? $t['signer_role']) . ($t['needs_approval'] ? ' approves' : ' signs')) ?></small></td>
  <td><?php if ($t['active']): ?><a href="?view=templates&tid=<?= (int)$t['id'] ?>">Change</a> <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="a" value="tploff"><input type="hidden" name="tid" value="<?= (int)$t['id'] ?>"><input name="reason" placeholder="Reason" required size="10"><button class="btn sm ghost">Switch off</button></form><?php else: ?><span class="pill off">Off</span> <?= h($t['off_reason']) ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="tpl"><input type="hidden" name="tid" value="<?= (int)($edit['id'] ?? 0) ?>"><h2><?= $edit ? 'Change ' . h($edit['name']) : 'New template' ?></h2>
  <div class="grid"><label>Code <input name="code" value="<?= h($edit['code'] ?? '') ?>" required maxlength="12"></label><label>Name <input name="name" value="<?= h($edit['name'] ?? '') ?>" required maxlength="80"></label>
  <label>Answer <select name="resp"><?php foreach (LET_RESP as $k => $v): ?><option value="<?= $k ?>"<?= ($edit['resp'] ?? '') === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
  <label>Signs <select name="signer_role"><?php foreach (role_labels() as $k => $v): ?><option value="<?= $k ?>"<?= ($edit['signer_role'] ?? 'HRMANAGER') === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label></div>
  <p><label class="tick"><input type="checkbox" name="needs_approval" value="1"<?= !empty($edit['needs_approval']) ? ' checked' : '' ?>> The signatory approves before it is issued</label> <label class="tick"><input type="checkbox" name="for_employees" value="1"<?= !isset($edit) || !empty($edit['for_employees']) ? ' checked' : '' ?>> Shown on My letters; employees may ask for it</label></p>
  <label>Text <textarea name="body" rows="14" required><?= h($edit['body'] ?? '') ?></textarea></label>
  <p class="muted">Fields: <?= h(implode(', ', array_map(fn($f) => '{{' . $f . '}}', LET_AUTO_FIELDS))) ?> fill themselves; any other {{field}} is typed per letter. Blank line = new paragraph, "- " = bullet, **bold**. Issued letters keep the text they were issued with.</p>
  <button class="btn sm">Save</button></form>

<?php elseif ($view === 'signers' && $canSet): ?>
<section class="card"><h2>Signatories</h2><table><thead><tr><th>Role</th><th>Name</th><th>Title</th><th>From</th></tr></thead><tbody><?php foreach (let_signers($db) as $s): ?><tr><td><?= h(role_labels()[$s['role_code']] ?? $s['role_code']) ?></td><td><?= h($s['name']) ?></td><td><?= h($s['title']) ?></td><td><?= h($s['from_day']) ?></td></tr><?php endforeach; ?></tbody></table>
  <p class="muted">The newest row valid on the letter's date signs it; earlier letters keep the name they were issued with.</p></section>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="signer"><h2>Set a signatory</h2><div class="grid">
  <label>Role <select name="role_code"><?php foreach (role_labels() as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select></label>
  <label>Name as printed <input name="name" required maxlength="120"></label><label>Title <input name="title" required maxlength="120"></label><label>Valid from <input type="date" name="from_day" value="<?= date('Y-m-d') ?>"></label></div><button class="btn sm">Save</button></form>

<?php else:
    $all = $isHr ? let_list($db, $u, (string)($_GET['st'] ?? '')) : []; ?>
<?php if ($isHr): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="save"><h2>New letter</h2><div class="grid">
  <label>Type <select name="template_id" required><?php foreach ($tpls as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></label>
  <label>Employee <select name="emp_code"><option value="">— not an employee yet —</option><?php foreach (emp_on_roll($db, $u) as $p): ?><option value="<?= h($p['code']) ?>"><?= h($p['name'] . ' · ' . $p['code']) ?></option><?php endforeach; ?></select></label>
  <label>…or name <input name="person" maxlength="160"></label><label>Email <input type="email" name="email"></label></div><button class="btn sm">Make the letter</button></form>
<section class="card"><h2>Letters</h2>
  <form class="row"><select name="st" onchange="this.form.submit()"><option value="">Every status</option><?php foreach (LET_STATUS as $k => $v): ?><option value="<?= $k ?>"<?= ($_GET['st'] ?? '') === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></form>
  <table><thead><tr><th>Ref</th><th>Type</th><th>Person</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($all as $l): ?><tr><td><?= h((string)$l['ref']) ?></td><td><?= h($l['tname']) ?></td><td><?= h($l['person']) ?></td><td><?= $st($l) ?></td><td><a href="?id=<?= (int)$l['id'] ?>">Open</a></td></tr><?php endforeach; ?>
  <?= $all ? '' : '<tr><td colspan="5" class="muted">No letters yet.</td></tr>' ?></tbody></table></section>
<?php else: ?><section class="card"><p>Letters waiting for your approval show under <b>To approve</b>.</p></section><?php endif; ?>
<?php endif;
ui_foot();
