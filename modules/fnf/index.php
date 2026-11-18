<?php
// Full & final — leavers, settlement form, steps (draft → checked → approved → paid), documents, bank line, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/leave.php';
require_once WN_ROOT . '/lib/fnf.php';
$u = module_require('fnf');
$db = pdo();
$set = fnf_settings();
if (!fnf_can($u, 'see', $set)) { http_response_code(403); ui_head('Full & final', $u, 'fnf'); echo '<section class="card"><p>Settlements carry pay figures, so they open only to logins with pay data (Super Admin decides).</p></section>'; ui_foot(); exit; }
$view = (string)($_GET['view'] ?? 'list');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$M = fn($v) => h(pay_money((float)$v));
$isAdmin = has_role($u, 'SUPERADMIN');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $to = 'index.php';
    try {
        switch ($a) {
            case 'save': $id = fnf_save($db, $u, $id ?: null, $_POST); flash('Saved.'); $to = 'index.php?view=one&id=' . $id; break;
            case 'check': case 'approve': case 'back': case 'paid': case 'off':
                fnf_step($db, $u, $id, $a, (string)($_POST['note'] ?? '')); flash(['check' => 'Checked.', 'approve' => 'Approved.', 'back' => 'Sent back to draft.', 'paid' => 'Marked paid.', 'off' => 'Switched off.'][$a]);
                $to = $a === 'off' ? 'index.php' : 'index.php?view=one&id=' . $id; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = fnf_settings_check($_POST, array_column(lv_types($db, false), null, 'code'), role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['fnf'] ?? ['roles' => module_roles('fnf')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['fnf', 1, implode(',', $st['roles'] ?: module_roles('fnf')), json_encode($new), (int)$u['id']]);
                audit((int)$u['id'], 'module_settings', 'fnf');
                flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'save') $to = 'index.php?view=edit' . ($id ? '&id=' . $id : '&code=' . urlencode((string)($_POST['code'] ?? ''))); elseif ($id) $to = 'index.php?view=one&id=' . $id; }
    redirect($to);
}

$r = $id ? fnf_get($db, $id) : null;
if ($r) { $e = emp_by_code($db, $r['emp_code']); if ($e && !emp_in_scope($u, $e)) $r = null; }
if ($id && !$r && $view !== 'list') { http_response_code(404); exit('Not found'); }

if ($view === 'bank' && $r) {
    try { $b = fnf_bank($db, $u, $r); } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); redirect('index.php?view=one&id=' . $id); }
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="final-settlement-' . $r['id'] . '.csv"'); header('Cache-Control: no-store');
    echo bank_csv_line(['Name', 'Account', 'Bank code', 'Amount', 'Reference']);
    foreach ($b['rows'] as $x) echo bank_csv_line([$x['name'], $x['acct'], $x['code'], number_format($x['amount'], 2, '.', ''), $x['ref']]);
    exit;
}

ui_head('Full & final', $u, 'fnf'); ?>
<nav class="row"><a class="btn sm<?= in_array($view, ['list', 'one', 'edit'], true) ? '' : ' ghost' ?>" href="index.php">Settlements</a><?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php if ($view === 'list'):
    $leavers = fnf_can($u, 'prepare', $set) ? fnf_leavers($db, $u) : []; $list = fnf_list($db, $u); ?>
<section class="card"><h2>Settlements</h2><table><thead><tr><th>Person</th><th>Last working day</th><th>Step</th><th></th></tr></thead><tbody>
<?php foreach ($list as $x): ?><tr><td><?= h($x['name'] . ' · ' . $x['emp_code']) ?></td><td><?= h(date('j M Y', strtotime($x['lwd']))) ?></td><td><span class="pill <?= $x['status'] === 'paid' ? 'on' : 'port' ?>"><?= h(FNF_STATUS[$x['status']]) ?></span><?= $x['back_note'] ? ' <small class="muted">Sent back: ' . h($x['back_note']) . '</small>' : '' ?></td><td><a href="?view=one&id=<?= (int)$x['id'] ?>">Open</a></td></tr><?php endforeach; ?>
<?= $list ? '' : '<tr><td colspan="4" class="muted">None yet.</td></tr>' ?></tbody></table></section>
<?php if (fnf_can($u, 'prepare', $set)): ?>
<section class="card"><h2>Leavers</h2><p class="muted">People with a last day on the employee master in the last six months. Start a settlement and check every figure it fills in.</p>
  <table><tbody><?php foreach ($leavers as $l): ?><tr><td><?= h($l['name'] . ' · ' . $l['code']) ?></td><td><?= h(date('j M Y', strtotime($l['last_day']))) ?></td><td><?= $l['has_fnf'] ? '<span class="pill on">Settlement started</span>' : '<a class="btn sm" href="?view=edit&code=' . urlencode($l['code']) . '">Start settlement</a>' ?></td></tr><?php endforeach; ?>
  <?= $leavers ? '' : '<tr><td class="muted">Nobody — set the last day on the employee master first.</td></tr>' ?></tbody></table></section>
<?php endif; ?>

<?php elseif ($view === 'edit'):
    if ($r) { $f = $r['f']; if ($r['status'] !== 'draft') { echo '<section class="card"><p>Only a draft changes.</p></section>'; ui_foot(); exit; } }
    else { $e = emp_by_code($db, strtoupper((string)($_GET['code'] ?? ''))); if (!$e || !emp_in_scope($u, $e)) { echo '<section class="card"><p>Pick a leaver from the list.</p></section>'; ui_foot(); exit; } $f = fnf_prefill($db, $e); }
    $c = fnf_context($db, $f); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="save"><?php if ($r): ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?php endif; ?>
  <h2><?= $r ? 'Settlement' : 'New settlement' ?> · <?= h($f['name'] . ' · ' . $f['code']) ?></h2>
  <?php if (!empty($f['missing'])): ?><p class="flash err"><?= h($f['missing']) ?></p><?php endif; ?>
  <input type="hidden" name="code" value="<?= h($f['code']) ?>"><input type="hidden" name="name" value="<?= h($f['name']) ?>">
  <div class="grid"><label>Designation <input name="designation" value="<?= h($f['designation']) ?>"></label><label>Department <input name="department" value="<?= h($f['department']) ?>"></label>
  <label>Branch <input name="branch" value="<?= h($f['branch']) ?>"></label><label>State (for tax) <input name="state" value="<?= h($f['state']) ?>"></label>
  <label>Joined <input type="date" name="doj" value="<?= h($f['doj']) ?>"></label><label>Resigned on <input type="date" name="resigned" value="<?= h($f['resigned']) ?>"></label>
  <label>Last working day <input type="date" name="lwd" value="<?= h($f['lwd']) ?>" required></label><label>Settlement date <input type="date" name="settle_date" value="<?= h($f['settle_date']) ?>"></label>
  <label>Reason for leaving <input name="reason" value="<?= h($f['reason']) ?>"></label><label>Monthly gross <input type="number" step="0.01" min="0" name="rate" value="<?= h((string)$f['rate']) ?>" required></label></div>
  <label>Address for the documents <input name="address" value="<?= h($f['address']) ?>"></label>
  <h3>Leave</h3><p class="muted">Counted: <?= h(implode(', ', $c['basis']['codes']) ?: 'none') ?> · <?= h((string)$c['basis']['days']) ?> days a year, pro-rated to the leaving month.</p>
  <div class="grid"><label>Counted leave taken this year <input type="number" step="0.5" min="0" name="taken" value="<?= h((string)$f['taken']) ?>"></label>
  <label>Leave days to adjust (blank = work it out) <input type="number" step="0.01" min="0" name="adj_days" value="<?= h((string)$f['adj_days']) ?>"></label></div>
  <p><label class="tick"><input type="checkbox" name="probation" value="1"<?= $f['probation'] ? ' checked' : '' ?>> On probation at the last working day (no leave cut)</label> <label class="tick"><input type="checkbox" name="tax_exempt" value="1"<?= $f['tax_exempt'] ? ' checked' : '' ?>> Exempt from tax by state</label></p>
  <h3>Months to pay</h3><table><thead><tr><th>Month</th><th>Days</th><th>Full month as agreed</th></tr></thead><tbody>
  <?php foreach (array_merge($f['months'], [['ym' => '', 'days' => '', 'full' => false]]) as $i => $m): ?><tr><td><input type="month" name="m_ym[<?= $i ?>]" value="<?= h((string)$m['ym']) ?>"></td><td><input type="number" step="0.5" min="0" name="m_days[<?= $i ?>]" value="<?= h((string)$m['days']) ?>"></td><td><input type="checkbox" name="m_full[<?= $i ?>]" value="1"<?= $m['full'] ? ' checked' : '' ?>></td></tr><?php endforeach; ?></tbody></table>
  <div class="grid2"><div><h3>Other earnings</h3><?php foreach (array_merge($f['earn'], [['label' => '', 'amount' => ''], ['label' => '', 'amount' => '']]) as $i => $x): ?><div class="row"><input name="earn_label[<?= $i ?>]" placeholder="e.g. Bonus" value="<?= h((string)$x['label']) ?>"><input type="number" step="1" name="earn_amt[<?= $i ?>]" value="<?= h((string)$x['amount']) ?>"></div><?php endforeach; ?></div>
  <div><h3>Other deductions</h3><?php foreach (array_merge($f['ded'], [['label' => '', 'amount' => ''], ['label' => '', 'amount' => '']]) as $i => $x): ?><div class="row"><input name="ded_label[<?= $i ?>]" placeholder="e.g. Notice shortfall" value="<?= h((string)$x['label']) ?>"><input type="number" step="1" name="ded_amt[<?= $i ?>]" value="<?= h((string)$x['amount']) ?>"></div><?php endforeach; ?></div></div>
  <div class="grid"><label>Already paid against this settlement <input type="number" step="1" min="0" name="paid_before" value="<?= h((string)$f['paid_before']) ?>"></label><label>How it was paid <input name="paid_before_note" value="<?= h($f['paid_before_note']) ?>"></label></div>
  <label>Note <input name="note" value="<?= h($f['note']) ?>"></label>
  <button class="btn sm">Save draft</button> <a class="btn sm ghost" href="<?= $r ? '?view=one&id=' . (int)$r['id'] : 'index.php' ?>">Cancel</a></form>

<?php elseif ($view === 'one' && $r):
    $f = $r['f']; $fig = fnf_figures($db, $f); $L = $fig['leave']; $cur = org('currency');
    $log = $db->prepare('SELECT l.*, us.name AS who FROM fnf_log l LEFT JOIN users us ON us.id = l.by_user WHERE l.fnf_id = ? ORDER BY l.id DESC'); $log->execute([$r['id']]); ?>
<section class="card"><h2><?= h($r['name'] . ' · ' . $r['emp_code']) ?> <span class="pill <?= $r['status'] === 'paid' ? 'on' : 'port' ?>"><?= h(FNF_STATUS[$r['status']]) ?></span><?= (int)$r['active'] ? '' : ' <span class="pill off">Switched off</span>' ?></h2>
  <p class="muted">Last working day <?= h(date('j M Y', strtotime($r['lwd']))) ?><?= $r['back_note'] ? ' · Sent back: ' . h($r['back_note']) : '' ?><?= $r['paid_ref'] ? ' · Paid: ' . h($r['paid_ref']) : '' ?></p>
  <div class="grid2"><div><b>Earnings</b><table><tbody><?php foreach ($fig['lines'] as $l): ?><tr><td><?= h($l['label']) ?></td><td><?= $M($l['amount']) ?></td></tr><?php endforeach; ?>
    <?php foreach ($fig['earn'] as $x): ?><tr><td><?= h($x['label']) ?></td><td><?= $M($x['amount']) ?></td></tr><?php endforeach; ?><tr><td><b>Total</b></td><td><b><?= $M($fig['earn_total']) ?></b></td></tr></tbody></table></div>
  <div><b>Deductions</b><table><tbody><?php foreach ($fig['lines'] as $l) if ($l['tax'] > 0): ?><tr><td>Tax by state · <?= h(date('M Y', strtotime($l['ym'] . '-01'))) ?></td><td><?= $M($l['tax']) ?></td></tr><?php endif; ?>
    <?php if ($L['cut'] > 0): ?><tr><td>Leave adjusted (<?= h((string)$L['excess']) ?> days × <?= $M($L['per_day']) ?>)</td><td><?= $M($L['cut']) ?></td></tr><?php endif; ?>
    <?php foreach ($fig['ded'] as $x): ?><tr><td><?= h($x['label']) ?></td><td><?= $M($x['amount']) ?></td></tr><?php endforeach; ?><tr><td><b>Total</b></td><td><b><?= $M($fig['ded_total']) ?></b></td></tr></tbody></table></div></div>
  <p><span class="pill">Net <?= $M($fig['net']) ?></span><?= $fig['paid_before'] ? ' <span class="pill">Already paid ' . $M($fig['paid_before']) . '</span>' : '' ?> <span class="pill on">Balance <?= $M($fig['balance']) ?></span> <small class="muted"><?= h(fnf_words($fig['balance'], $cur)) ?></small></p>
  <p class="muted">Leave: <?= $L['probation'] ? 'on probation — no cut.' : h($L['entitled'] . ' days due for ' . $L['months'] . ' months, ' . $L['taken'] . ' taken') . ($L['typed'] ? ' · days typed by HR (worked out: ' . h((string)$L['worked']) . ')' : '') . ($L['encash'] ? ' · unused paid' : '') ?></p>
  <?php if ((int)$r['active']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <?php if ($r['status'] === 'draft' && fnf_can($u, 'prepare', $set)): ?><a class="btn sm ghost" href="?view=edit&id=<?= (int)$r['id'] ?>">Edit</a><?php endif; ?>
    <?php foreach (['check' => 'Mark checked', 'approve' => 'Approve'] as $k => $l) if (!fnf_step_problem($u, $r, $k, $set)): ?><button class="btn sm" name="a" value="<?= $k ?>"><?= $l ?></button><?php endif; ?>
    <input name="note" placeholder="<?= $r['status'] === 'approved' ? 'Payment reference, or reason' : 'Reason' ?>">
    <?php if (!fnf_step_problem($u, $r, 'paid', $set, 'x')): ?><button class="btn sm" name="a" value="paid">Mark paid</button><?php endif; ?>
    <?php if (!fnf_step_problem($u, $r, 'back', $set, 'x')): ?><button class="btn sm ghost" name="a" value="back">Send back</button><?php endif; ?>
    <?php if (!fnf_step_problem($u, $r, 'off', $set, 'x')): ?><button class="btn sm ghost" name="a" value="off">Switch off</button><?php endif; ?></form><?php endif; ?>
  <p>Documents: <?php foreach (FNF_DOCS as $k => $l): ?><a class="btn sm ghost" href="print.php?id=<?= (int)$r['id'] ?>&doc=<?= $k ?>" target="_blank"><?= h($l) ?></a> <?php endforeach; ?>
    <?php if (in_array($r['status'], ['approved', 'paid'], true) && bank_can($u)): ?><a class="btn sm ghost" href="?view=bank&id=<?= (int)$r['id'] ?>">Bank line (CSV)</a><?php endif; ?>
    <?php if (module_on('letters')): ?><a class="btn sm ghost" href="../letters/">Relieving / experience letter</a><?php endif; ?></p></section>
<section class="card"><h3>Log</h3><table><tbody><?php foreach ($log as $l): ?><tr><td><?= h(date('j M Y H:i', strtotime($l['at']))) ?></td><td><?= h($l['text']) ?></td><td><?= h((string)$l['who']) ?></td></tr><?php endforeach; ?></tbody></table></section>

<?php elseif ($view === 'settings' && $isAdmin): $types = lv_types($db, false); $b = fnf_leave_basis($types, $set); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
  <p>Leave counted for the cut (none ticked = every paid type with a yearly allowance): <?php foreach ($types as $t): ?><label class="tick"><input type="checkbox" name="leave_codes[]" value="<?= h($t['code']) ?>"<?= in_array($t['code'], $set['leave_codes'], true) ? ' checked' : '' ?>> <?= h($t['name']) ?></label> <?php endforeach; ?></p>
  <div class="grid"><label>Leave days a year (blank = from the leave types: <?= h((string)$b['days']) ?>) <input name="leave_days" value="<?= h((string)($set['leave_days'] ?? '')) ?>"></label>
  <label>Most unused days paid (0 = no limit) <input type="number" min="0" max="366" name="encash_max" value="<?= (int)$set['encash_max'] ?>"></label></div>
  <p><label class="tick"><input type="checkbox" name="pay_unused" value="1"<?= $set['pay_unused'] ? ' checked' : '' ?>> Pay unused leave in the settlement</label></p>
  <?php foreach (['prepare_roles' => 'Prepare, check and mark paid', 'approve_roles' => 'Approve'] as $k => $lab): ?><p><?= h($lab) ?>: <?php foreach (role_labels() as $rc => $rl): ?><label class="tick"><input type="checkbox" name="<?= $k ?>[]" value="<?= h($rc) ?>"<?= in_array($rc, $set[$k], true) ? ' checked' : '' ?>> <?= h($rl) ?></label> <?php endforeach; ?></p><?php endforeach; ?>
  <label>Wording on the acknowledgement (blank = the standard text) <textarea name="notice_text" rows="3"><?= h($set['notice_text']) ?></textarea></label>
  <p class="muted">Amounts open only to logins with pay data. Nobody prepares, checks or approves their own settlement; the checker and approver are never the preparer.</p>
  <button class="btn sm">Save</button></form>
<?php endif;
ui_foot();
