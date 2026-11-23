<?php
// Vendor bills paid from head office — my bills + new bill, check / approve / pay queues, one bill (change, send,
// decide, files, history), all bills with totals and CSV, repeating bills, settings. Rules in lib/vendorbills.php.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/vendorbills.php';
$u = module_require('vendorbills');
$db = pdo(); $set = vb_settings(); $c = vb_ctx($db, $u); $uid = (int)$u['id'];
$isAdmin = has_role($u, 'SUPERADMIN');
$allBn = []; foreach (branches(false) as $b) $allBn[(int)$b['id']] = $b['name'];
$enterBn = array_intersect_key($allBn, array_flip($c['enter']));
$kinds = vb_kinds($set);
$view = (string)($_GET['v'] ?? 'mine');
$files = function (string $k): array {
    $o = [];
    foreach ((array)($_FILES[$k]['tmp_name'] ?? []) as $i => $tmp) if (is_uploaded_file((string)$tmp)) $o[] = [(string)file_get_contents((string)$tmp), (string)$_FILES[$k]['name'][$i]];
    return $o;
};

if ($view === 'file') {
    $f = vb_file_get($db, $c, (int)($_GET['id'] ?? 0));
    if (!$f) { http_response_code(404); exit('Not found'); }
    header('Content-Type: ' . $f['mime']); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'"); header('Cache-Control: no-store');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$f['name']) . '"');
    echo $f['bytes']; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $to = 'index.php?v=bill&id=' . $id;
    try {
        switch ($a) {
            case 'save': $id = vb_save($db, $u, $id, $_POST, $files('files'), !empty($_POST['send'])); flash(!empty($_POST['send']) ? 'Sent to be checked.' : 'Saved as a draft.'); $to = 'index.php?v=bill&id=' . $id; break;
            case 'withdraw': vb_withdraw($db, $u, $id, (string)($_POST['note'] ?? '')); flash('Withdrawn; the bill is kept.'); break;
            case 'decide': vb_decide($db, $u, $id, (string)$_POST['step'], (string)$_POST['decision'], (string)($_POST['note'] ?? ''), $_POST, $files('slip')); flash('Done.');
                $to = 'index.php?v=' . (in_array($_POST['step'] ?? '', ['check', 'approve', 'pay'], true) ? $_POST['step'] : 'mine'); break;
            case 'slip': vb_add_slip($db, $u, $id, $files('slip')); flash('Payment slip added.'); break;
            case 'repeat': vb_repeat_save($db, $u, $_POST); flash('Repeating bill saved; a draft is made each month on its day.'); $to = 'index.php?v=repeat'; break;
            case 'repeat_stop': vb_repeat_stop($db, $u, $id); flash('Stopped.'); $to = 'index.php?v=repeat'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = vb_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['vendorbills'] ?? ['roles' => module_roles('vendorbills')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['vendorbills', 1, implode(',', $st['roles'] ?: module_roles('vendorbills')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'vendorbills'); flash('Settings saved.'); $to = 'index.php?v=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'save' && !$id) $to = 'index.php?v=new'; if (in_array($a, ['repeat', 'repeat_stop'], true)) $to = 'index.php?v=repeat'; }
    redirect($to);
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : date('Y-m-01', strtotime('-2 months'));
$tod = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : date('Y-m-d');
$fb = (int)($_GET['branch'] ?? 0); $fs = (string)($_GET['status'] ?? '');
$seeAll = $c['R']['view'] || $c['R']['check'] || $c['R']['approve'] || $c['R']['pay'];
if ($view === 'csv' && $seeAll) {
    $rows = vb_list($db, $c, 'all', ['branch' => $fb, 'status' => $fs, 'from' => $from, 'to' => $tod]);
    header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store'); header('Content-Disposition: attachment; filename="vendor-bills-' . $from . '-' . $tod . '.csv"');
    audit($uid, 'vendorbill_export', count($rows) . ' rows'); echo "\xEF\xBB\xBF" . vb_csv($rows, $allBn); exit;
}
try { if (random_int(1, 5) === 1 || $view === 'repeat') vb_repeat_tick($db); } catch (Throwable $e) {}

$W = vb_waiting($db, $c);
$m = fn($v) => h(number_format((float)$v, 2));
$hid = fn(string $a, int $id) => csrf_field() . '<input type="hidden" name="a" value="' . h($a) . '"><input type="hidden" name="id" value="' . $id . '">';
$sel = fn(array $o, string $n, string $cur, bool $blank = true) => '<select name="' . $n . '">' . ($blank ? '<option value="">—</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o)) . '</select>';
$form = function (array $b) use ($enterBn, $kinds, $sel, $db): string {
    $v = fn(string $k) => h((string)($b[$k] ?? ''));
    return '<div class="grid"><label>Branch * ' . $sel($enterBn, 'branch', (string)($b['branch_id'] ?? (count($enterBn) === 1 ? array_key_first($enterBn) : '')), true) . '</label>'
        . '<label>For * ' . $sel(array_combine($kinds, $kinds), 'kind', (string)($b['kind'] ?? '')) . '</label><label>Vendor (who is paid) <input name="vendor" list="vbv" value="' . $v('vendor') . '"></label>'
        . '<label>Vendor bill / account no. <input name="vendor_bill_no" value="' . $v('vendor_bill_no') . '"></label><label>Bill date <input type="date" name="bill_date" value="' . $v('bill_date') . '"></label>'
        . '<label>Month <input type="month" name="period" value="' . $v('period') . '"></label><label>Amount <input name="amount" inputmode="decimal" value="' . ((float)($b['amount'] ?? 0) > 0 ? $v('amount') : '') . '"></label>'
        . '<label>Due date <input type="date" name="due_date" value="' . $v('due_date') . '"></label><label>Where to pay (as on the bill) <input name="pay_to" value="' . $v('pay_to') . '"></label></div>'
        . '<label>Note <input name="note" maxlength="600" value="' . $v('note') . '"></label><div class="row"><label><input type="checkbox" name="no_bill" value="1"' . (!empty($b['no_bill']) ? ' checked' : '') . '> No bill copy</label> <label>Why <input name="no_bill_why" value="' . $v('no_bill_why') . '"></label></div>'
        . '<label>Bill copy (PDF or photo) <input type="file" name="files[]" accept="image/*,application/pdf" multiple></label>'
        . '<datalist id="vbv">' . implode('', array_map(fn($x) => '<option value="' . h($x) . '">', vb_vendors($db))) . '</datalist>'
        . '<div class="row"><button class="btn" name="send" value="1">Send to be checked</button> <button class="btn ghost">Save as draft</button></div>';
};
$row = fn(array $b) => '<tr><td><a href="?v=bill&id=' . (int)$b['id'] . '">' . h($b['ref']) . '</a></td><td>' . h(vb_title($b)) . '<div class="muted">' . h($allBn[(int)$b['branch_id']] ?? '') . ' · ' . h($b['entered_name']) . '</div></td><td>' . $m($b['amount']) . '</td><td>' . h((string)($b['due_date'] ?? '')) . '</td><td>' . h(VB_STATUSES[$b['status']] ?? $b['status']) . '</td></tr>';
$tab = fn(string $k, string $l, int $n = 0) => ' <a class="btn sm' . ($view === $k ? '' : ' ghost') . '" href="?v=' . $k . '">' . h($l) . ($n ? ' <b>' . $n . '</b>' : '') . '</a>';
ui_head('Vendor bills', $u, 'vendorbills'); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('mine', 'My bills', $W['returned'] + $W['drafts']) ?><?= $c['enter'] ? $tab('new', 'New bill') : '' ?>
<?= $c['R']['check'] ? $tab('check', 'To check', $W['check']) : '' ?><?= $c['R']['approve'] ? $tab('approve', 'To approve', $W['approve']) : '' ?><?= $c['R']['pay'] ? $tab('pay', 'To pay', $W['pay']) : '' ?>
<?= $seeAll ? $tab('all', 'All bills') : '' ?><?= $c['enter'] ? $tab('repeat', 'Repeating') : '' ?><?= $isAdmin ? $tab('settings', 'Settings') : '' ?></nav>

<?php if ($view === 'mine'): $rows = vb_list($db, $c, 'mine'); ?>
<div class="card"><h2>Bills I entered</h2><?php if (!$rows): ?><p class="muted">None yet.</p><?php endif; ?><table><?php foreach ($rows as $b) echo $row($b); ?></table></div>

<?php elseif ($view === 'new' && $c['enter']): ?>
<form method="post" enctype="multipart/form-data" class="card"><?= $hid('save', 0) ?><h2>New vendor bill</h2><?= $form([]) ?></form>

<?php elseif (in_array($view, ['check', 'approve', 'pay'], true) && $c['R'][$view]): $rows = vb_list($db, $c, $view); ?>
<div class="card"><h2><?= ['check' => 'Waiting to be checked', 'approve' => 'Waiting for approval', 'pay' => 'Approved · to pay'][$view] ?></h2><?php if (!$rows): ?><p class="muted">Nothing waiting.</p><?php endif; ?>
<table><?php foreach ($rows as $b) echo $row($b); ?></table><p class="muted">Bills you entered are not listed here; someone else decides on them.</p></div>

<?php elseif ($view === 'all' && $seeAll): $rows = vb_list($db, $c, 'all', ['branch' => $fb, 'status' => $fs, 'from' => $from, 'to' => $tod]); $S = vb_summary($rows); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="v" value="all"><input type="date" name="from" value="<?= h($from) ?>" aria-label="From"><input type="date" name="to" value="<?= h($tod) ?>" aria-label="To">
<select name="branch" aria-label="Branch"><option value="0">All branches</option><?php foreach ($allBn as $i => $n): if ($c['see'] === null || in_array($i, (array)$c['see'], true)): ?><option value="<?= $i ?>"<?= $fb === $i ? ' selected' : '' ?>><?= h($n) ?></option><?php endif; endforeach; ?></select>
<?= $sel(VB_STATUSES, 'status', $fs) ?><button class="btn sm">Show</button> <a class="btn sm ghost" href="?<?= h(http_build_query(['v' => 'csv', 'from' => $from, 'to' => $tod, 'branch' => $fb, 'status' => $fs])) ?>">CSV</a></form>
<div class="card"><p>Paid <b><?= $m($S['paid']) ?></b> · still coming <b><?= $m($S['coming']) ?></b></p><div class="grid">
<div><h3>Paid by branch</h3><table><?php foreach ($S['byBranch'] as $i => $v): ?><tr><td><?= h($allBn[$i] ?? '') ?></td><td><?= $m($v) ?></td></tr><?php endforeach; ?></table></div>
<div><h3>Paid by kind</h3><table><?php foreach ($S['byKind'] as $k => $v): ?><tr><td><?= h((string)$k) ?></td><td><?= $m($v) ?></td></tr><?php endforeach; ?></table></div>
<div><h3>Paid by month</h3><table><?php foreach ($S['byMonth'] as $k => $v): ?><tr><td><?= h((string)$k) ?></td><td><?= $m($v) ?></td></tr><?php endforeach; ?></table></div></div></div>
<div class="card"><table><?php foreach ($rows as $b) echo $row($b); ?></table><?php if (!$rows): ?><p class="muted">No bills in these dates.</p><?php endif; ?></div>

<?php elseif ($view === 'bill' && ($b = (function () use ($db) { try { return vb_get($db, (int)($_GET['id'] ?? 0)); } catch (Throwable $e) { return null; } })()) && vb_sees($c, $b)):
    $fl = vb_files($db, [(int)$b['id']])[(int)$b['id']] ?? []; $mine = (int)$b['entered_by'] === $uid; $same = vb_same_period($db, $b); ?>
<div class="card"><h2><?= h($b['ref']) ?> · <?= h(vb_title($b)) ?></h2><p><b><?= h(VB_STATUSES[$b['status']] ?? $b['status']) ?></b></p>
<table><tr><th>Branch</th><td><?= h($allBn[(int)$b['branch_id']] ?? '') ?></td></tr><tr><th>Amount</th><td><?= $m($b['amount']) ?></td></tr>
<tr><th>Vendor bill / account no.</th><td><?= h($b['vendor_bill_no']) ?></td></tr><tr><th>Bill date · due</th><td><?= h((string)$b['bill_date']) ?> · <?= h((string)$b['due_date']) ?></td></tr>
<tr><th>Where to pay</th><td><?= h($b['pay_to']) ?></td></tr><tr><th>Note</th><td><?= h($b['note']) ?><?= $b['no_bill'] ? '<br>No bill copy: ' . h($b['no_bill_why']) : '' ?></td></tr>
<tr><th>Entered by</th><td><?= h($b['entered_name']) ?></td></tr>
<?php if ($b['check_by']): ?><tr><th>Checked</th><td><?= h((string)$b['check_at']) ?> <?= h($b['check_note']) ?></td></tr><?php endif; ?>
<?php if ($b['approve_by']): ?><tr><th>Approval</th><td><?= h((string)$b['approve_at']) ?> <?= h($b['approve_note']) ?></td></tr><?php endif; ?>
<?php if ($b['status'] === 'paid'): ?><tr><th>Paid</th><td><?= h((string)$b['paid_on']) ?> · <?= h($b['pay_mode']) ?> · <?= h($b['pay_ref']) ?> <?= h($b['pay_note']) ?></td></tr><?php endif; ?></table>
<?php if ($same && in_array($b['status'], ['submitted', 'checked'], true)): ?><p style="color:#b42318">Another bill of the same kind, vendor and month is already in: <?= h($same) ?>.</p><?php endif; ?>
<p><?php foreach ($fl as $f): ?><a href="?v=file&id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= h(($f['kind'] === 'slip' ? 'Payment slip: ' : '') . $f['name']) ?></a> <?php endforeach; ?></p></div>
<?php foreach (['check' => ['submitted', ['ok' => 'Checked, send for approval', 'return' => 'Return for correction']], 'approve' => ['checked', ['approve' => 'Approve', 'return' => 'Return for correction', 'reject' => 'Reject']]] as $step => [$st, $ds]):
    if ($b['status'] === $st && vb_can($c, $step, $b)): ?><form method="post" class="card"><?= $hid('decide', (int)$b['id']) ?><input type="hidden" name="step" value="<?= $step ?>"><h3><?= $step === 'check' ? 'Check' : 'Approve' ?></h3>
<label>Note (needed to return or reject) <input name="note" maxlength="600"></label><div class="row"><?php foreach ($ds as $k => $l): ?><button class="btn<?= $k === 'ok' || $k === 'approve' ? '' : ' ghost' ?>" name="decision" value="<?= $k ?>"><?= h($l) ?></button> <?php endforeach; ?></div></form><?php endif; endforeach; ?>
<?php if ($b['status'] === 'approved' && vb_can($c, 'pay', $b)): ?><form method="post" enctype="multipart/form-data" class="card"><?= $hid('decide', (int)$b['id']) ?><input type="hidden" name="step" value="pay"><input type="hidden" name="decision" value="paid"><h3>Mark paid</h3>
<div class="grid"><label>Paid on * <input type="date" name="paid_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></label><label>How * <?= $sel(array_combine(VB_PAY_MODES, VB_PAY_MODES), 'pay_mode', '') ?></label><label>Transaction reference / cheque no. <input name="pay_ref" maxlength="120"></label></div>
<label>Payment slip (if no reference) <input type="file" name="slip[]" accept="image/*,application/pdf"></label><label>Note <input name="note" maxlength="600"></label><button class="btn">Mark paid</button></form><?php endif; ?>
<?php if ($b['status'] === 'paid' && vb_can($c, 'pay', $b)): ?><form method="post" enctype="multipart/form-data" class="card row"><?= $hid('slip', (int)$b['id']) ?><input type="file" name="slip[]" accept="image/*,application/pdf" required><button class="btn sm ghost">Add payment slip</button></form><?php endif; ?>
<?php if (in_array($b['status'], ['draft', 'returned'], true) && ($mine || ($c['R']['check'] && vb_sees($c, $b)))): ?><form method="post" enctype="multipart/form-data" class="card"><?= $hid('save', (int)$b['id']) ?><h3>Change<?= $b['status'] === 'returned' ? ' and send again' : '' ?></h3><?= $form($b) ?></form><?php endif; ?>
<?php if (in_array($b['status'], ['draft', 'submitted', 'returned'], true) && ($mine || ($c['R']['check'] && vb_sees($c, $b)))): ?><form method="post" class="card row"><?= $hid('withdraw', (int)$b['id']) ?><input name="note" placeholder="Why withdraw" maxlength="600"><button class="btn sm ghost">Withdraw</button></form><?php endif; ?>
<div class="card"><h3>History</h3><table><?php foreach (vb_history($db, (int)$b['id']) as $l): ?><tr><td><?= h((string)$l['at']) ?></td><td><?= h((string)($l['who'] ?? 'System')) ?></td><td><?= h($l['action']) ?></td><td><?= h($l['note']) ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($view === 'repeat' && $c['enter']): ?>
<div class="card"><h2>Repeating bills</h2><p class="muted">Each month on the bill day a draft is made for the person who set it up, to add the amount and bill copy and send.</p>
<table><?php foreach (vb_repeats($db, $c) as $r): ?><tr><td><?= h($r['kind']) ?> · <?= h($r['vendor']) ?></td><td><?= h($allBn[(int)$r['branch_id']] ?? '') ?></td><td>Day <?= (int)$r['bill_day'] ?><?= $r['due_day'] ? ', due ' . (int)$r['due_day'] : '' ?><?= $r['amount'] !== null ? ' · ' . $m($r['amount']) : '' ?></td><td class="muted"><?= h((string)$r['by_name']) ?></td>
<td><form method="post"><?= $hid('repeat_stop', (int)$r['id']) ?><button class="btn sm ghost">Stop</button></form></td></tr><?php endforeach; ?></table></div>
<form method="post" class="card"><?= $hid('repeat', 0) ?><h3>Add a repeating bill</h3><div class="grid"><label>Branch * <?= $sel($enterBn, 'branch', count($enterBn) === 1 ? (string)array_key_first($enterBn) : '') ?></label><label>For * <?= $sel(array_combine($kinds, $kinds), 'kind', '') ?></label>
<label>Vendor * <input name="vendor" required></label><label>Bill comes on day * <input type="number" name="bill_day" min="1" max="28" value="1" required></label><label>Due on day <input type="number" name="due_day" min="1" max="28"></label><label>Usual amount <input name="amount" inputmode="decimal"></label>
<label>Where to pay <input name="pay_to"></label></div><button class="btn">Save</button></form>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['view_roles' => 'See every bill of their branches', 'check_roles' => 'Check', 'approve_roles' => 'Approve', 'pay_roles' => 'Pay from head office'] as $k => $l): ?>
<h3><?= h($l) ?></h3><div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $code => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, (array)$set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<label>Tell the pay roles this many days before an approved bill falls due <input type="number" name="due_warn_days" min="0" max="30" value="<?= (int)$set['due_warn_days'] ?>"></label>
<label>Kinds of bill (one per line) <textarea name="kinds" rows="8"><?= h($set['kinds']) ?></textarea></label>
<p class="muted">Nobody checks, approves or pays a bill they entered. Super Admin and CEO see every branch; others their ticked branches (none ticked = every branch). Emails go only when Email is on.</p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
