<?php
// Expense claims — my claims, the claim form (lines + bills), one claim with its steps, queues, report, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/claims.php';
$u = module_require('claims');
$db = pdo();
$set = ec_settings();
$view = (string)($_GET['view'] ?? 'mine');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$M = fn($v) => h(number_format((float)$v, 2));
$cur = org('currency');
$isAdmin = has_role($u, 'SUPERADMIN');
$rev = ec_reviewer($u, $set);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $to = 'index.php';
    try {
        switch ($a) {
            case 'save': case 'send':
                [$id, $dups] = ec_save($db, $u, $id, $_POST, ec_uploads($_FILES), $a === 'send');
                flash(($a === 'send' ? 'Sent to HR.' : 'Draft saved.') . ($dups ? ' Please check: ' . implode(' ', array_merge(...array_values($dups))) : ''), $dups ? 'err' : 'ok');
                $to = 'index.php?view=one&id=' . $id; break;
            case 'withdraw': ec_withdraw($db, $u, $id, (string)($_POST['note'] ?? '')); flash('Withdrawn.'); $to = 'index.php?view=one&id=' . $id; break;
            case 'line': ec_line_decide($db, $u, $id, (int)$_POST['line'], !empty($_POST['allow']), (string)($_POST['note'] ?? '')); flash('Saved.'); $to = 'index.php?view=one&id=' . $id; break;
            case 'step': ec_decide($db, $u, $id, (string)$_POST['step'], (string)$_POST['decision'], (string)($_POST['note'] ?? ''), $_POST); flash('Done.'); $to = 'index.php?view=one&id=' . $id; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = ec_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['claims'] ?? ['roles' => module_roles('claims')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['claims', 1, implode(',', $st['roles'] ?: module_roles('claims')), json_encode($new), (int)$u['id']]);
                audit((int)$u['id'], 'module_settings', 'claims');
                flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); $to = $id ? 'index.php?view=' . ($a === 'save' || $a === 'send' ? 'edit' : 'one') . '&id=' . $id : ($a === 'save' || $a === 'send' ? 'index.php?view=edit' : 'index.php'); }
    redirect($to);
}

if ($view === 'file') {
    $q = $db->prepare('SELECT * FROM ec_file WHERE id = ? AND active = 1'); $q->execute([(int)($_GET['f'] ?? 0)]); $f = $q->fetch();
    $c = $f ? ec_get($db, (int)$f['claim_id']) : null;
    if (!$f || !$c || !ec_may_see($db, $u, $c, $set)) { http_response_code(404); exit('Not found'); }
    if ((int)$c['user_id'] !== (int)$u['id']) audit((int)$u['id'], 'claim_file_view', '#' . $c['id']);
    header('Content-Type: ' . $f['mime']); header('Content-Disposition: inline; filename="bill-' . (int)$f['id'] . '"');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    echo $f['bytes']; exit;
}

$w = ec_waiting($db, $u);
$tabs = ['mine' => 'My claims'] + ($rev ? ['queue' => 'To act on' . (($n = $w['audit'] + $w['approve'] + $w['pay']) ? " ($n)" : ''), 'all' => 'All claims'] : [])
      + (ec_role($u, 'report', $set) ? ['report' => 'Report'] : []) + ($isAdmin ? ['settings' => 'Settings'] : []);
$sel = fn(array $opts, string $name, string $curv) => '<select name="' . h($name) . '">' . implode('', array_map(fn($o) => '<option' . ($o === $curv ? ' selected' : '') . '>' . h($o) . '</option>', $opts)) . '</select>';
$table = function (array $rows, bool $who) use ($M): void { ?>
  <table><thead><tr><th>Claim</th><?php if ($who): ?><th>Person</th><?php endif; ?><th>What for</th><th>Spent</th><th>To pay</th><th>Step</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $c): ?><tr><td><?= h($c['claim_no']) ?></td><?php if ($who): ?><td><?= h((string)$c['person']) ?></td><?php endif; ?><td><?= h($c['title']) ?></td><td><?= $M($c['amount']) ?></td><td><?= $M(ec_payable($c)) ?></td>
    <td><span class="pill <?= $c['status'] === 'paid' ? 'on' : (in_array($c['status'], ['rejected', 'withdrawn'], true) ? 'off' : 'port') ?>"><?= h(EC_STATUS[$c['status']] ?? $c['status']) ?></span></td><td><a href="?view=<?= $c['status'] === 'draft' ? 'edit' : 'one' ?>&id=<?= (int)$c['id'] ?>">Open</a></td></tr><?php endforeach; ?>
  <?= $rows ? '' : '<tr><td colspan="7" class="muted">None.</td></tr>' ?></tbody></table>
<?php };

ui_head('Expense claims', $u, 'claims'); ?>
<nav class="row"><?php foreach ($tabs as $k => $l): ?><a class="btn sm<?= $view === $k ? '' : ' ghost' ?>" href="?view=<?= $k ?>"><?= h($l) ?></a><?php endforeach; ?> <a class="btn sm" href="?view=edit">New claim</a></nav>

<?php if ($view === 'mine'): $rows = ec_list($db, $u, 'mine'); ?>
<section class="card"><h2>My claims</h2><?php if ($w['returned']): ?><p class="flash err"><?= $w['returned'] ?> returned to you — correct and send again.</p><?php endif; ?><?php $table($rows, false); ?></section>

<?php elseif ($view === 'queue' && $rev): ?>
<?php foreach (['audit' => 'To audit', 'approve' => 'To approve', 'pay' => 'To pay'] as $k => $l) if (ec_role($u, $k, $set)): ?><section class="card"><h2><?= $l ?></h2><?php $table(ec_list($db, $u, $k), true); ?></section><?php endif; ?>

<?php elseif ($view === 'all' && $rev): ?>
<section class="card"><h2>All claims</h2><?php $table(ec_list($db, $u, 'all'), true); ?></section>

<?php elseif ($view === 'edit'):
    $c = $id ? ec_get($db, $id) : null;
    if ($c && ((int)$c['user_id'] !== (int)$u['id'] || !in_array($c['status'], ['draft', 'returned', 'submitted'], true))) { echo '<section class="card"><p>This claim can no longer be changed.</p></section>'; ui_foot(); exit; }
    $L = $c ? ec_lines($db, $id) : []; $F = $c ? ec_files($db, $id) : [];
    $rows = array_merge($L, array_fill(0, max(3, 6 - count($L)), null)); $brs = branches(); ?>
<form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><?php if ($c): ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><?php endif; ?>
  <h2><?= $c ? h($c['claim_no']) : 'New claim' ?></h2>
  <?php if ($c && $c['status'] === 'returned'): ?><p class="flash err">Returned: <?= h($c['audit_note'] ?: $c['approve_note']) ?></p><?php endif; ?>
  <label>Title (optional) <input name="title" value="<?= h($c['title'] ?? '') ?>" maxlength="200"></label>
  <table><thead><tr><th>Spent / received</th><th>Date</th><th>What for</th><th>Category</th><th>Paid by</th><th>Amount (<?= h($cur) ?>)</th><th>Bill</th><th>Remarks</th></tr></thead><tbody>
  <?php foreach ($rows as $i => $l): $k = 'r' . $i; $bills = $l ? array_filter($F, fn($f) => (int)$f['line_id'] === (int)$l['id']) : []; ?>
    <tr><td><input type="hidden" name="lines[<?= $k ?>][id]" value="<?= (int)($l['id'] ?? 0) ?>"><select name="lines[<?= $k ?>][kind]"><option value="dr">Spent</option><option value="cr"<?= ($l['kind'] ?? '') === 'cr' ? ' selected' : '' ?>>Received</option></select></td>
      <td><input type="date" name="lines[<?= $k ?>][date]" value="<?= h((string)($l['line_date'] ?? '')) ?>" max="<?= date('Y-m-d') ?>"></td><td><input name="lines[<?= $k ?>][particulars]" value="<?= h((string)($l['particulars'] ?? '')) ?>"></td>
      <td><?= $sel(array_merge([''], $set['categories'], $set['credit_cats']), "lines[$k][category]", (string)($l['category'] ?? '')) ?></td><td><?= $sel(array_merge([''], $set['modes']), "lines[$k][mode]", (string)($l['mode'] ?? '')) ?></td>
      <td><input name="lines[<?= $k ?>][amount]" value="<?= h((string)($l['amount'] ?? '')) ?>" inputmode="decimal" size="9"></td>
      <td><?php foreach ($bills as $f): ?><a href="?view=file&f=<?= (int)$f['id'] ?>" target="_blank">Bill</a> <?php endforeach; ?><input type="file" name="bill[<?= $k ?>][]" accept="application/pdf,image/jpeg,image/png,image/webp" capture="environment" multiple>
        <label class="tick"><input type="checkbox" name="lines[<?= $k ?>][no_receipt]" value="1"<?= !empty($l['no_receipt']) ? ' checked' : '' ?>> No bill</label></td>
      <td><input name="lines[<?= $k ?>][remarks]" value="<?= h((string)($l['remarks'] ?? '')) ?>"><?php if (count($brs) > 1): ?><select name="lines[<?= $k ?>][branch_id]"><option value="">For: —</option><?php foreach ($brs as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)($l['branch_id'] ?? 0) === (int)$b['id'] ? ' selected' : '' ?>>For <?= h($b['name']) ?></option><?php endforeach; ?></select><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <p class="muted">Each expense needs its bill (photo or file, up to 10 MB) or "No bill" with a reason. Money received for this work (an advance, cash from the office) is taken off what is paid. Save as a draft to add more rows.</p>
  <?php if (!$c || $c['status'] === 'draft'): ?><button class="btn sm ghost" name="a" value="save">Save as draft</button><?php endif; ?> <button class="btn sm" name="a" value="send">Send to HR</button></form>

<?php elseif ($view === 'one' && $id):
    $c = ec_get($db, $id);
    if (!$c || !ec_may_see($db, $u, $c, $set)) { echo '<section class="card"><p>Not found.</p></section>'; ui_foot(); exit; }
    $own = (int)$c['user_id'] === (int)$u['id']; $L = ec_lines($db, $id); $F = ec_files($db, $id); $t = ec_totals($L); $dup = ec_dup_flags($db, $c);
    $log = $db->prepare('SELECT l.*, us.name AS who FROM ec_log l LEFT JOIN users us ON us.id = l.user_id WHERE l.claim_id = ? ORDER BY l.id'); $log->execute([$id]);
    $auditing = !$own && $c['status'] === 'submitted' && ec_role($u, 'audit', $set); ?>
<section class="card"><h2><?= h($c['claim_no']) ?> · <?= h($c['title']) ?> <span class="pill <?= $c['status'] === 'paid' ? 'on' : 'port' ?>"><?= h(EC_STATUS[$c['status']]) ?></span></h2>
  <p class="muted"><?= h((string)$c['person']) ?><?= (int)$c['round'] > 1 ? ' · round ' . (int)$c['round'] : '' ?></p>
  <table><thead><tr><th>Date</th><th>What for</th><th>Category</th><th>Paid by</th><th>Amount</th><th>Bill</th><th></th></tr></thead><tbody>
  <?php foreach ($L as $l): $bills = array_filter($F, fn($f) => (int)$f['line_id'] === (int)$l['id']); ?>
    <tr class="<?= (int)$l['allowed'] ? '' : 'muted' ?>"><td><?= h(date('j M Y', strtotime($l['line_date']))) ?></td><td><?= $l['kind'] === 'cr' ? '<span class="pill">Received</span> ' : '' ?><?= h($l['particulars']) ?><?= $l['remarks'] !== '' ? '<br><small class="muted">' . h($l['remarks']) . '</small>' : '' ?>
      <?= isset($dup[(int)$l['id']]) ? '<br><small class="flash err">' . h(implode(' ', $dup[(int)$l['id']])) . '</small>' : '' ?><?= (int)$l['allowed'] ? '' : '<br><small>Not allowed: ' . h($l['disallow_note']) . '</small>' ?></td>
      <td><?= h($l['category']) ?></td><td><?= h($l['mode']) ?></td><td><?= $M($l['amount']) ?></td><td><?php foreach ($bills as $f): ?><a href="?view=file&f=<?= (int)$f['id'] ?>" target="_blank">Bill</a> <?php endforeach; ?><?= (int)$l['no_receipt'] ? 'No bill' : '' ?></td>
      <td><?php if ($auditing && $l['kind'] === 'dr'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="line"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line" value="<?= (int)$l['id'] ?>">
        <?php if ((int)$l['allowed']): ?><input name="note" placeholder="Why not allowed" size="14"><button class="btn sm ghost">Not allowed</button><?php else: ?><button class="btn sm ghost" name="allow" value="1">Allow</button><?php endif; ?></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <p><span class="pill">Spent <?= $M($t['spent']) ?></span><?= $t['allowed'] < $t['spent'] ? ' <span class="pill port">Allowed ' . $M($t['allowed']) . '</span>' : '' ?><?= $t['received'] ? ' <span class="pill">Received ' . $M($t['received']) . '</span>' : '' ?> <span class="pill on"><?= ec_payable($c) < 0 ? 'To give back ' . $M(-ec_payable($c)) : 'To pay ' . $M(ec_payable($c)) ?></span></p>
  <?php if ($c['status'] === 'paid'): ?><p>Paid on <?= h(date('j M Y', strtotime((string)$c['paid_on']))) ?> · <?= h($c['pay_mode']) ?><?= $c['pay_ref'] !== '' ? ' · ' . h($c['pay_ref']) : '' ?></p><?php endif; ?>
  <?php if ($own && in_array($c['status'], ['draft', 'submitted', 'returned'], true)): ?><p><a class="btn sm ghost" href="?view=edit&id=<?= $id ?>">Change</a></p>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="withdraw"><input type="hidden" name="id" value="<?= $id ?>"><input name="note" placeholder="Reason (optional)"><button class="btn sm ghost">Withdraw</button></form><?php endif; ?>
  <?php if (!$own): foreach (['audit' => ['ok' => 'Audit done — send for approval', 'return' => 'Return to the person'], 'approve' => ['approve' => 'Approve', 'back' => 'Send back to HR', 'reject' => 'Reject']] as $step => $ds) if (ec_role($u, $step, $set) && $c['status'] === ['audit' => 'submitted', 'approve' => 'audited'][$step]): ?>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="step"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="step" value="<?= $step ?>"><input name="note" placeholder="Note"><?php foreach ($ds as $d => $lab): ?><button class="btn sm<?= in_array($d, ['ok', 'approve'], true) ? '' : ' ghost' ?>" name="decision" value="<?= $d ?>"><?= h($lab) ?></button> <?php endforeach; ?></form>
  <?php endif; if (ec_role($u, 'pay', $set) && $c['status'] === 'approved'): ?>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="step"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="step" value="pay"><input type="hidden" name="decision" value="paid">
      <input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"><?= $sel(array_merge([''], $set['pay_modes']), 'pay_mode', '') ?><input name="pay_ref" placeholder="Payment reference"><button class="btn sm"><?= ec_payable($c) < 0 ? 'Mark settled' : 'Mark paid' ?></button></form>
  <?php endif; endif; ?></section>
<section class="card"><h3>History</h3><table><tbody><?php foreach ($log as $l): ?><tr><td><?= h(date('j M Y H:i', strtotime($l['at']))) ?></td><td><?= h(ucfirst($l['action'])) ?><?= $l['note'] !== '' ? ' — ' . h($l['note']) : '' ?></td><td><?= h((string)$l['who']) ?></td></tr><?php endforeach; ?></tbody></table></section>

<?php elseif ($view === 'report' && ec_role($u, 'report', $set)):
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01', strtotime('-5 months')); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
    $r = ec_report($db, $u, $from, $to); ?>
<section class="card"><h2>Report</h2><form class="row"><input type="hidden" name="view" value="report"><input type="date" name="from" value="<?= h($from) ?>"><input type="date" name="to" value="<?= h($to) ?>"><button class="btn sm ghost">Show</button></form>
  <p><span class="pill">Claims <?= $r['claims'] ?></span> <span class="pill on">Paid <?= $M($r['paid']) ?></span> <span class="pill port">In process <?= $M($r['open']) ?></span></p>
  <div class="grid2"><table><thead><tr><th>Category</th><th>Allowed spending</th></tr></thead><tbody><?php foreach ($r['by_category'] as $k => $v): ?><tr><td><?= h($k) ?></td><td><?= $M($v) ?></td></tr><?php endforeach; ?></tbody></table>
  <table><thead><tr><th>Month</th><th>Allowed spending</th></tr></thead><tbody><?php foreach ($r['by_month'] as $k => $v): ?><tr><td><?= h(date('M Y', strtotime($k . '-01'))) ?></td><td><?= $M($v) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <p class="muted">By step: <?= h(implode(' · ', array_map(fn($k, $v) => (EC_STATUS[$k] ?? $k) . ' ' . $v, array_keys($r['by_status']), $r['by_status']))) ?></p></section>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
  <div class="grid2"><?php foreach (['categories' => 'Expense categories', 'credit_cats' => 'Kinds of money received', 'modes' => 'How it was paid / received', 'pay_modes' => 'How claims are paid'] as $k => $lab): ?><label><?= h($lab) ?> (one per line) <textarea name="<?= $k ?>" rows="6"><?= h(implode("\n", $set[$k])) ?></textarea></label><?php endforeach; ?></div>
  <p><label class="tick"><input type="checkbox" name="bill_needed" value="1"<?= $set['bill_needed'] ? ' checked' : '' ?>> Each expense needs its bill or a reason</label></p>
  <div class="grid"><label>Oldest expense date (days back) <input type="number" name="days_back" min="1" max="1500" value="<?= (int)$set['days_back'] ?>"></label><label>Largest single line <input type="number" name="max_line" min="1" value="<?= (int)$set['max_line'] ?>"></label></div>
  <?php foreach (['audit_roles' => 'Audit (HR)', 'approve_roles' => 'Approve', 'pay_roles' => 'Pay', 'report_roles' => 'Report'] as $k => $lab): ?><p><?= h($lab) ?>: <?php foreach (role_labels() as $rc => $rl): ?><label class="tick"><input type="checkbox" name="<?= $k ?>[]" value="<?= h($rc) ?>"<?= in_array($rc, $set[$k], true) ? ' checked' : '' ?>> <?= h($rl) ?></label> <?php endforeach; ?></p><?php endforeach; ?>
  <p class="muted">Nobody audits, approves or pays their own claim. Reviewers see claims of people in their branches.</p><button class="btn sm">Save</button></form>
<?php endif;
ui_foot();
