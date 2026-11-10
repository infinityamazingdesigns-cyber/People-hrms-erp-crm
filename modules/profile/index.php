<?php
// My profile — the person's own details (sent to HR to verify) and the HR desk that verifies them.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/profile.php';
$u = module_require('profile');
$db = pdo();
$isHr = pf_is_hr($u);
$emp = !empty($u['emp_code']) ? emp_by_code($db, (string)$u['emp_code']) : null;
$view = ($_GET['view'] ?? '') === 'hr' && $isHr ? 'hr' : 'me';
$fmt = org('date_format') ?: 'd M Y';
$brName = array_column(branches(false), 'name', 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    try {
        if ($a === 'send' && $emp) {
            [$clean, $err] = pf_clean($_POST, pf_work_domain());
            if ($err) { $_SESSION['pf_draft'] = $_POST; flash(implode(' · ', $err), 'err'); redirect('index.php'); }
            $id = pf_submit($db, $u, $emp, $clean, (string)($_POST['note'] ?? ''));
            flash($id ? 'Sent to HR. Nothing changes until HR verifies it.' : 'Nothing changed.');
        } elseif ($a === 'withdraw') {
            flash(pf_withdraw($db, $u, (int)($_POST['id'] ?? 0)) ? 'Taken back.' : 'Nothing to take back.');
        } elseif ($a === 'decide' && $isHr) {
            $st = pf_decide($db, $u, (int)($_POST['id'] ?? 0), (array)($_POST['accept'] ?? []), (string)($_POST['hr_note'] ?? ''));
            flash('Saved: ' . strtolower(PF_STATUS[$st]) . '.');
            redirect('index.php?view=hr');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php' . ($view === 'hr' ? '?view=hr' : ''));
}

ui_head('My profile', $u, 'profile');
if ($isHr): $waiting = pf_waiting($db, $u); ?>
<nav class="row"><a class="btn sm<?= $view === 'me' ? '' : ' ghost' ?>" href="index.php">My profile</a>
  <a class="btn sm<?= $view === 'hr' ? '' : ' ghost' ?>" href="?view=hr">Profile updates to verify<?= $waiting ? ' · ' . count($waiting) : '' ?></a></nav>
<?php endif;

if ($view === 'hr'):
    $lab = fn($k) => PF_FIELDS[$k][0] ?? $k;
    if (!$waiting): ?><section class="card"><p>Nothing waiting.</p></section><?php endif;
    foreach ($waiting as $r): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="decide"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
  <h2><?= h(($r['name'] ?? $r['emp_code']) . ' · ' . $r['emp_code']) ?> <small class="muted"><?= h(($r['designation'] ?? '') . ' · ' . ($brName[$r['branch_id']] ?? '') . ' · sent ' . $r['created_at']) ?></small></h2>
  <?php if ($r['note'] !== ''): ?><p class="note">Note from them: <?= h($r['note']) ?></p><?php endif; ?>
  <?php if ($r['fields']): ?><table><thead><tr><th>Accept</th><th>Detail</th><th>On file</th><th>New</th></tr></thead><tbody>
  <?php foreach ($r['fields'] as $k => [$o, $n]): ?><tr><td><input type="checkbox" name="accept[]" value="<?= h($k) ?>" checked></td>
    <td><?= h(PF_SECTIONS[PF_FIELDS[$k][1]] . ' · ' . $lab($k)) ?></td><td><?= $o === '' ? '<em>—</em>' : nl2br(h($o)) ?></td><td><b><?= $n === '' ? '<em>cleared</em>' : nl2br(h($n)) ?></b></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
  <label>Note to them (needed when sending everything back) <input name="hr_note" maxlength="500"></label>
  <button class="btn sm">Save decision</button> <span class="muted">Unticked details are declined.</span>
</form>
<?php endforeach;

else:
    if (!$emp): ?><section class="card"><p>Your login is not linked to an employee record yet. Ask HR to link it on Users & access.</p></section>
    <?php ui_foot(); exit; endif;
    $p = pf_profile($db, $emp['code']);
    $open = pf_open($db, (int)$u['id']);
    $draft = $_SESSION['pf_draft'] ?? null; unset($_SESSION['pf_draft']);
    $val = fn($k) => (string)($draft[$k] ?? ($open['fields'][$k][1] ?? ($p[$k] ?? '')));
    [$done, $tot] = pf_completeness($p);
    $mgr = $emp['manager_code'] ? emp_by_code($db, $emp['manager_code']) : null; ?>
<section class="card"><h2>Employment details</h2>
  <div class="grid">
  <?php foreach (['Employee code' => $emp['code'], 'Name' => $emp['name'], 'Department' => $emp['department'], 'Designation' => $emp['designation'],
      'Branch' => $brName[$emp['branch_id']] ?? '', 'Date of joining' => $emp['doj'] ? date($fmt, strtotime($emp['doj'])) : '', 'Reports to' => $mgr['name'] ?? ''] as $l => $vv): ?>
    <div><small class="muted"><?= h($l) ?></small><br><b><?= h($vv ?: '—') ?></b></div><?php endforeach; ?>
  </div><p class="muted">Kept by HR. If something here is wrong, say so in the note at the end of the form.</p></section>

<?php if ($open): ?><div class="flash ok">Your changes are with HR (sent <?= h($open['created_at']) ?>). You can edit and send again, or
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="a" value="withdraw"><input type="hidden" name="id" value="<?= (int)$open['id'] ?>"><button class="btn sm ghost">take them back</button></form>.</div><?php endif; ?>

<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="send">
  <h2>Your details <small class="muted"><?= $done ?> of <?= $tot ?> filled</small></h2>
  <?php foreach (PF_SECTIONS as $sk => $sl): ?><fieldset><legend><?= h($sl) ?></legend><div class="grid">
  <?php foreach (PF_FIELDS as $k => [$label, $sec, $type, $opt, $hint]): if ($sec !== $sk) continue; $v = $val($k); $pend = isset($open['fields'][$k]); ?>
    <label><?= h($label) ?><?= $pend ? ' <span class="pill port">with HR</span>' : '' ?>
    <?php if ($type === 'select'): ?><select name="<?= $k ?>"><option value="">—</option><?php foreach ($opt as $o): ?><option<?= $v === $o ? ' selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select>
    <?php elseif ($type === 'area'): ?><textarea name="<?= $k ?>" rows="2" maxlength="<?= (int)$opt ?>"><?= h($v) ?></textarea>
    <?php else: ?><input name="<?= $k ?>" value="<?= h($v) ?>" type="<?= ['date' => 'date', 'email' => 'email', 'tel' => 'tel'][$type] ?? 'text' ?>"<?= is_int($opt) && $opt ? ' maxlength="' . $opt . '"' : '' ?><?= $type === 'tel' ? ' inputmode="tel"' : '' ?>><?php endif; ?>
    <?php if ($hint): ?><small class="muted"><?= h($hint) ?></small><?php endif; ?></label>
  <?php endforeach; ?></div></fieldset><?php endforeach; ?>
  <label>Note to HR (optional — e.g. an employment detail above is wrong) <input name="note" maxlength="500" value="<?= h((string)($draft['note'] ?? ($open['note'] ?? ''))) ?>"></label>
  <button class="btn">Send to HR</button>
</form>

<?php $hist = pf_history($db, (int)$u['id']); if ($hist): ?>
<section class="card"><h2>Your earlier changes</h2><table><thead><tr><th>Sent</th><th>Details</th><th>Status</th><th>HR note</th></tr></thead><tbody>
<?php foreach ($hist as $r): ?><tr><td><?= h($r['created_at']) ?></td><td><?= h(implode(', ', array_map(fn($k) => PF_FIELDS[$k][0] ?? $k, array_keys($r['fields']))) ?: 'Note only') ?></td>
<td><span class="pill <?= in_array($r['status'], ['approved'], true) ? 'on' : ($r['status'] === 'rejected' ? 'off' : '') ?>"><?= h(PF_STATUS[$r['status']] ?? $r['status']) ?></span></td><td><?= h($r['hr_note']) ?></td></tr><?php endforeach; ?>
</tbody></table></section>
<?php endif;
endif;
ui_foot();
