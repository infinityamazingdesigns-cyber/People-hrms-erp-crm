<?php
// Branch day book — the day sheet (enter, close), one entry (change, remove, files, settle), reports and the change
// register for viewers, CSV, settings. Rules in lib/daybook.php.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/daybook.php';
$u = module_require('daybook');
$db = pdo(); $set = dbk_settings(); $c = dbk_ctx($db, $u); $uid = (int)$u['id'];
$isAdmin = has_role($u, 'SUPERADMIN');
$allBn = []; foreach (branches(false) as $b) $allBn[(int)$b['id']] = $b['name'];
$entryBn = array_values(dbk_entry_branches($c));
$sheetBn = []; foreach (branches() as $b) { $i = (int)$b['id']; if (in_array($i, $entryBn, true) || (dbk_is_viewer($c) && dbk_view_ok($c, $i))) $sheetBn[$i] = $b['name']; }
$view = (string)($_GET['view'] ?? 'day');

if ($view === 'file') {
    $f = dbk_file_get($db, $c, (int)($_GET['id'] ?? 0));
    if (!$f) { http_response_code(404); exit('Not found'); }
    header('Content-Type: ' . $f['type']); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'"); header('Cache-Control: no-store');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$f['name']) . '"');
    echo $f['data']; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0);
    $b = (int)($_POST['branch'] ?? 0); $day = (string)($_POST['day'] ?? date('Y-m-d'));
    $to = 'index.php?' . http_build_query(['branch' => $b, 'day' => $day]);
    try {
        switch ($a) {
            case 'add':
                $id = dbk_add($db, $c, $b, $day, (string)$_POST['kind'], $_POST);
                foreach ((array)($_FILES['files']['tmp_name'] ?? []) as $i => $tmp) if (is_uploaded_file((string)$tmp)) dbk_file_add($db, $c, $id, (string)file_get_contents((string)$tmp), (string)$_FILES['files']['name'][$i]);
                flash('Saved.'); break;
            case 'edit': dbk_edit($db, $c, $id, $_POST); flash('Changed; the earlier values are in the register.'); $to = 'index.php?view=entry&id=' . $id; break;
            case 'remove': dbk_remove($db, $c, $id, (string)($_POST['reason'] ?? '')); flash('Removed; the entry is kept with your reason.'); break;
            case 'file':
                $n = 0; foreach ((array)($_FILES['files']['tmp_name'] ?? []) as $i => $tmp) if (is_uploaded_file((string)$tmp)) { dbk_file_add($db, $c, $id, (string)file_get_contents((string)$tmp), (string)$_FILES['files']['name'][$i]); $n++; }
                flash($n ? "$n file(s) added." : 'Pick a file.', $n ? 'ok' : 'err'); $to = 'index.php?view=entry&id=' . $id; break;
            case 'close': dbk_close($db, $c, $b, $day, $_POST['counted'] ?? '', (string)($_POST['note'] ?? '')); flash('Day closed.'); break;
            case 'check': dbk_check($db, $c, $b, $day, (string)($_POST['note'] ?? '')); flash('Day checked.'); break;
            case 'reopen': dbk_reopen($db, $c, $b, $day, (string)($_POST['note'] ?? '')); flash('Day reopened.'); break;
            case 'opening': dbk_set_opening($db, $c, $b, $day, $_POST['amount'] ?? '', (string)($_POST['note'] ?? '')); flash('Opening balance set.'); break;
            case 'settle': dbk_settle($db, $c, $id, (string)($_POST['note'] ?? '')); flash('Marked settled.'); $to = (string)($_POST['back'] ?? '') === 'report' ? 'index.php?view=report' : 'index.php?view=entry&id=' . $id; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = dbk_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['daybook'] ?? ['roles' => module_roles('daybook')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['daybook', 1, implode(',', $st['roles'] ?: module_roles('daybook')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'daybook'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if (in_array($a, ['edit', 'file'], true)) $to = 'index.php?view=entry&id=' . $id; }
    redirect($to);
}

try { [$from, $tod] = dbk_range((string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? '')); } catch (RuntimeException $e) { [$from, $tod] = dbk_range('', ''); }
$fb = (int)($_GET['branch'] ?? 0);
if ($view === 'csv') {
    $rows = dbk_is_viewer($c) ? dbk_report($db, $c, $from, $tod, $fb && dbk_view_ok($c, $fb) ? $fb : 0)['entries'] : dbk_entries($db, $c, $from, $tod, ['user' => $uid]);
    header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store'); header('Content-Disposition: attachment; filename="day-book-' . $from . '-' . $tod . '.csv"');
    audit($uid, 'daybook_export', count($rows) . ' rows'); echo "\xEF\xBB\xBF" . dbk_csv($rows, $allBn); exit;
}

$cats = dbk_petty_cats($set);
$hid = fn(string $a, int $id = 0, int $b = 0, string $d = '') => csrf_field() . '<input type="hidden" name="a" value="' . h($a) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="branch" value="' . $b . '"><input type="hidden" name="day" value="' . h($d) . '">';
$opt = fn(array $l, string $cur, bool $blank = true) => ($blank ? '<option value="">—</option>' : '') . implode('', array_map(fn($x) => '<option' . ($x === $cur ? ' selected' : '') . '>' . h($x) . '</option>', $l));
$m = fn($v) => h(dbk_money((float)$v));
/** Fields for one kind of entry, filled with $d (edit) or blank. */
$fields = function (string $kind, array $d, array $bills) use ($cats, $opt): string {
    $v = fn(string $k) => h((string)($d[$k] ?? ''));
    $ck = fn(string $k) => !empty($d[$k]) ? ' checked' : '';
    $billSel = fn(bool $req) => '<label>Bill' . ($req ? ' *' : ' (optional)') . ' <select name="bill"' . ($req ? ' required' : '') . '><option value="">—</option>' . implode('', array_map(fn($x) => '<option value="' . $x['id'] . '"' . ((int)($d['bill'] ?? 0) === $x['id'] ? ' selected' : '') . '>' . h($x['no'] . ' · ' . $x['party'] . ' · ' . dbk_money($x['amount']) . ($x['sent'] ? ' · sent' : '')) . '</option>', $bills)) . '</select></label>';
    return match ($kind) {
        'bill' => '<div class="grid"><label>Bill no. <input name="bill_no" value="' . $v('bill_no') . '"></label><label>Challan no. <input name="challan" value="' . $v('challan') . '"></label>'
            . '<label>Party * <input name="party" list="dbkp" required value="' . $v('party') . '"></label><label>City <input name="city" value="' . $v('city') . '"></label><label>Amount * <input name="amount" inputmode="decimal" required value="' . $v('amount') . '"></label></div>'
            . '<div class="row"><label><input type="checkbox" name="frt_ours" value="1"' . $ck('frt_ours') . '> Freight paid by us</label> <label><input type="checkbox" name="frt_in_bill" value="1"' . $ck('frt_in_bill') . '> Freight in the bill</label> <label>Freight amount <input name="frt_amt" inputmode="decimal" value="' . $v('frt_amt') . '"></label></div>',
        'dispatch' => '<div class="grid">' . $billSel(true) . '<label>How * <select name="how"><option value="direct"' . (($d['how'] ?? '') === 'direct' ? ' selected' : '') . '>Direct to party</option><option value="lr"' . (($d['how'] ?? '') === 'lr' ? ' selected' : '') . '>By transporter (LR)</option></select></label>'
            . '<label>Transporter <input name="transporter" list="dbkt" value="' . $v('transporter') . '"></label><label>LR no. <input name="lr_no" value="' . $v('lr_no') . '"></label>'
            . '<label>Local transport charge <input name="auto_amt" inputmode="decimal" value="' . $v('auto_amt') . '"></label><label>Paid by <select name="auto_mode">' . $opt(DBK_PAY_MODES, (string)($d['auto_mode'] ?? '')) . '</select></label>'
            . '<label>Freight paid by <select name="frt_by">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . (($d['frt_by'] ?? 'none') === $k ? ' selected' : '') . '>' . $l . '</option>', ['none', 'ours', 'party'], ['Nobody / not yet', 'Us', 'The party'])) . '</select></label>'
            . '<label>Freight amount <input name="frt_amt" inputmode="decimal" value="' . $v('frt_amt') . '"></label><label>Freight paid by <select name="frt_mode">' . $opt(DBK_PAY_MODES, (string)($d['frt_mode'] ?? '')) . '</select></label></div>',
        'receipt' => '<div class="grid">' . $billSel(false) . '<label>Party (blank = the bill\'s) <input name="party" list="dbkp" value="' . $v('party') . '"></label><label>City <input name="city" value="' . $v('city') . '"></label>'
            . '<label>Amount * <input name="amount" inputmode="decimal" required value="' . $v('amount') . '"></label><label>How * <select name="mode" required>' . $opt(DBK_RECEIPT_MODES, (string)($d['mode'] ?? '')) . '</select></label>'
            . '<label>Into (UPI / bank) <select name="into"><option value="company"' . (($d['into'] ?? '') !== 'person' ? ' selected' : '') . '>The company account</option><option value="person"' . (($d['into'] ?? '') === 'person' ? ' selected' : '') . '>A person\'s own account</option></select></label>'
            . '<label>Whose account <input name="person" value="' . $v('person') . '"></label><label>Payment reference <input name="ref" value="' . $v('ref') . '"></label></div>',
        'petty' => '<div class="grid"><label>Particulars * <input name="particulars" required value="' . $v('particulars') . '"></label><label>Category * <select name="category" required>' . $opt($cats, (string)($d['category'] ?? '')) . '</select></label>'
            . '<label>Purpose <input name="purpose" value="' . $v('purpose') . '"></label><label>Bill no. <input name="bill_no" value="' . $v('bill_no') . '"></label><label>Amount * <input name="amount" inputmode="decimal" required value="' . $v('amount') . '"></label>'
            . '<label>Paid by * <select name="mode" required>' . $opt(DBK_PETTY_MODES, (string)($d['mode'] ?? '')) . '</select></label></div>'
            . '<div class="row"><label><input type="checkbox" name="no_receipt" value="1"' . $ck('no_receipt') . '> No receipt</label> <label>Why (paid to whom, why) <input name="no_receipt_why" value="' . $v('no_receipt_why') . '"></label></div>',
        default => '',
    } . '<label>Note <input name="note" maxlength="300" value="' . $v('note') . '"></label>';
};
$parties = dbk_parties($db);
$lists = '<datalist id="dbkp">' . implode('', array_map(fn($p) => '<option value="' . h($p['name']) . '">' . h($p['city']) . '</option>', $parties['party'])) . '</datalist><datalist id="dbkt">' . implode('', array_map(fn($p) => '<option value="' . h($p['name']) . '">', $parties['transporter'])) . '</datalist>';

ui_head('Branch day book', $u, 'daybook'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'day' ? '' : ' ghost' ?>" href="index.php">Day sheet</a>
<a class="btn sm<?= $view === 'mine' ? '' : ' ghost' ?>" href="?view=mine"><?= dbk_is_viewer($c) ? 'All entries' : 'My entries' ?></a>
<?php if (dbk_is_viewer($c)): ?> <a class="btn sm<?= $view === 'report' ? '' : ' ghost' ?>" href="?view=report">Reports</a> <a class="btn sm<?= $view === 'register' ? '' : ' ghost' ?>" href="?view=register">Change register</a><?php endif; ?>
<?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>
<?php if (!dbk_can_use($c)): ?><div class="card"><b>No branch for your login.</b> Ask HR to link your employee record or tick your branch on Users.</div><?php ui_foot(); exit; endif; ?>

<?php if ($view === 'day'):
    $b = (int)($_GET['branch'] ?? 0); if (!isset($sheetBn[$b])) $b = (int)array_key_first($sheetBn);
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? (string)$_GET['day'] : date('Y-m-d');
    $V = dbk_day_view($db, $c, $b, $day); $bills = dbk_bill_choices($db, $b); $kind = (string)($_GET['kind'] ?? 'bill'); if (!isset(DBK_KINDS[$kind])) $kind = 'bill'; ?>
<form class="row card"><select name="branch" onchange="this.form.submit()"><?php foreach ($sheetBn as $i => $n): ?><option value="<?= $i ?>"<?= $i === $b ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
<input type="date" name="day" value="<?= h($day) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()"> <b><?= h(['open' => 'Open', 'closed' => 'Closed', 'checked' => 'Checked'][$V['state']] ?? $V['state']) ?></b></form>
<div class="card"><h2><?= h($allBn[$b] ?? '') ?> · <?= h(date('D j M Y', strtotime($day))) ?></h2>
<table><tr><th>Opening</th><td><?= $V['opening'] === null ? '<span class="muted">Not set</span>' : $m($V['opening']) . ($V['openingSrc'] === 'set' ? ' <span class="muted">(set by a viewer' . (($V['row']['opening_note'] ?? '') !== '' ? ': ' . h($V['row']['opening_note']) : '') . ')</span>' : '') ?></td></tr>
<tr><th>Cash in</th><td><?= $m($V['cashIn']) ?></td></tr><tr><th>Cash out</th><td><?= $m($V['cashOut']) ?></td></tr><tr><th>Closing (worked out)</th><td><b><?= $m($V['calc']) ?></b></td></tr>
<?php if ($V['state'] !== 'open'): ?><tr><th>Cash counted</th><td><?= $m($V['row']['counted']) ?><?= ($V['row']['close_note'] ?? '') !== '' ? ' · ' . h($V['row']['close_note']) : '' ?></td></tr><?php endif; ?>
<?php if ($V['state'] === 'checked'): ?><tr><th>Checked</th><td><?= h((string)$V['row']['checked_at']) ?><?= $V['row']['check_note'] !== '' ? ' · ' . h($V['row']['check_note']) : '' ?></td></tr><?php endif; ?></table>
<?php if ($V['others']): ?><p class="muted"><?= (int)$V['others'] ?> more entries by others are counted in the totals.</p><?php endif; ?></div>

<?php if ($V['canEnter']): ?><form method="post" enctype="multipart/form-data" class="card"><?= $hid('add', 0, $b, $day) ?><input type="hidden" name="kind" value="<?= h($kind) ?>">
<h2>Add</h2><div class="row" style="flex-wrap:wrap"><?php foreach (DBK_KINDS as $k => $l): ?><a class="btn sm<?= $k === $kind ? '' : ' ghost' ?>" href="?<?= h(http_build_query(['branch' => $b, 'day' => $day, 'kind' => $k])) ?>"><?= h($l) ?></a><?php endforeach; ?></div>
<?= $fields($kind, [], $bills) ?>
<?php if (in_array($kind, ['receipt', 'petty'], true)): ?><label><?= $kind === 'receipt' ? 'Payment screenshot (needed when into a person\'s account)' : 'Receipt' ?> <input type="file" name="files[]" accept="image/*,application/pdf" multiple></label><?php endif; ?>
<button class="btn">Save</button></form><?= $lists ?><?php endif; ?>

<div class="card"><h2>Entries <span class="muted"><?= count($V['entries']) ?></span></h2><?php if (!$V['entries']): ?><p class="muted">None yet.</p><?php endif; ?>
<table><?php foreach ($V['entries'] as $e): ?><tr><td><a href="?view=entry&id=<?= $e['id'] ?>"><?= h(DBK_KINDS[$e['kind']]) ?></a></td><td><?= h(dbk_summary($e['kind'], $e['data'] + ['party' => $e['party'], 'amount' => $e['amount']])) ?><?= $e['withEmp'] && !$e['settledAt'] ? ' <b>with employee</b>' : '' ?></td>
<td><?= $e['cash'] != 0 ? $m($e['cash']) : '' ?></td><td class="muted"><?= h($e['by']) ?> · <?= count($e['files']) ?> file(s)</td></tr><?php endforeach; ?></table></div>

<?php if ($V['state'] === 'open' && in_array($b, $entryBn, true)): ?><form method="post" class="card"><?= $hid('close', 0, $b, $day) ?><h2>Close the day</h2>
<?php foreach ($V['blocks'] as $x): ?><p style="color:#b42318"><?= h($x) ?></p><?php endforeach; ?>
<div class="row"><label>Cash counted <input name="counted" inputmode="decimal" required value="<?= h(number_format($V['calc'], 2, '.', '')) ?>"></label><label>Note (needed when it differs) <input name="note" maxlength="300"></label><button class="btn"<?= $V['blocks'] || $V['opening'] === null ? ' disabled' : '' ?>>Close</button></div>
<?php if ($V['opening'] === null): ?><p class="muted">The first opening balance of a branch is set by a viewer.</p><?php endif; ?></form><?php endif; ?>
<?php if ($V['viewer']): ?><div class="card"><h3>Viewer</h3>
<?php if ($V['state'] === 'open'): ?><form method="post" class="row"><?= $hid('opening', 0, $b, $day) ?><label>Opening balance <input name="amount" inputmode="decimal" required></label><input name="note" placeholder="Reason" required maxlength="300"><button class="btn sm ghost">Set opening</button></form><?php endif; ?>
<?php if ($V['state'] === 'closed'): ?><form method="post" class="row"><?= $hid('check', 0, $b, $day) ?><input name="note" placeholder="Note (optional)" maxlength="300"><button class="btn sm">Mark checked</button></form><?php endif; ?>
<?php if ($V['state'] !== 'open'): ?><form method="post" class="row"><?= $hid('reopen', 0, $b, $day) ?><input name="note" placeholder="Why reopen" required maxlength="300"><button class="btn sm ghost">Reopen</button></form><?php endif; ?></div><?php endif; ?>

<?php elseif ($view === 'entry' && ($r = dbk_entry_row($db, (int)($_GET['id'] ?? 0))) && dbk_entry_visible($c, $r)):
    $e = dbk_present($r, dbk_files_for($db, [(int)$r['id']])[(int)$r['id']] ?? []); $dr = dbk_day_row($db, $e['branch'], $e['day']); $open = !$dr || $dr['state'] === 'open'; $removed = $e['removedAt'] !== null; ?>
<div class="card"><h2><?= h(DBK_KINDS[$e['kind']]) ?> · <?= h($allBn[$e['branch']] ?? '') ?> · <?= h($e['day']) ?></h2>
<p><?= h(dbk_summary($e['kind'], $e['data'] + ['party' => $e['party'], 'amount' => $e['amount']])) ?></p>
<p class="muted">By <?= h($e['by']) ?> at <?= h($e['at']) ?><?= $e['editedAt'] ? ' · changed ' . h((string)$e['editedAt']) : '' ?><?= $removed ? ' · <b>removed</b> ' . h((string)$e['removedAt']) . ': ' . h($e['removeReason']) : '' ?></p>
<?php if ($e['withEmp']): ?><p><?= $e['settledAt'] ? 'Settled ' . h((string)$e['settledAt']) . ($e['settleNote'] !== '' ? ' · ' . h($e['settleNote']) : '') : '<b>With employee — to deposit</b> (' . h((string)($e['data']['person'] ?? '')) . ')' ?></p>
<?php if (!$e['settledAt'] && !$removed && dbk_is_viewer($c) && dbk_view_ok($c, $e['branch'])): ?><form method="post" class="row"><?= $hid('settle', $e['id']) ?><input name="note" placeholder="How it was settled" maxlength="300"><button class="btn sm">Mark settled</button></form><?php endif; endif; ?>
<?php foreach ($e['files'] as $f): ?><a href="?view=file&id=<?= $f['id'] ?>" target="_blank" rel="noopener"><?= h($f['name']) ?></a> <?php endforeach; ?></div>
<?php if (!$removed && $open && ($e['byId'] === $uid || (dbk_is_viewer($c) && dbk_view_ok($c, $e['branch'])))): ?>
<form method="post" class="card"><?= $hid('edit', $e['id']) ?><h3>Change</h3><?= $fields($e['kind'], $e['data'] + ['party' => $e['party'], 'city' => $e['city'], 'amount' => $e['amount']], dbk_bill_choices($db, $e['branch'])) ?><button class="btn">Save changes</button></form><?= $lists ?>
<form method="post" enctype="multipart/form-data" class="card row"><?= $hid('file', $e['id']) ?><input type="file" name="files[]" accept="image/*,application/pdf" multiple required><button class="btn sm ghost">Add file</button></form>
<form method="post" class="card row"><?= $hid('remove', $e['id'], $e['branch'], $e['day']) ?><input name="reason" placeholder="Why remove" required maxlength="300"><button class="btn sm ghost">Remove</button></form><?php endif; ?>

<?php elseif ($view === 'mine'): $rows = dbk_entries($db, $c, $from, $tod, ['branch' => $fb, 'user' => dbk_is_viewer($c) ? (int)($_GET['user'] ?? 0) : $uid, 'kind' => (string)($_GET['kind'] ?? '')]); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="mine"><input type="date" name="from" value="<?= h($from) ?>"><input type="date" name="to" value="<?= h($tod) ?>">
<select name="branch"><option value="0">All branches</option><?php foreach ($sheetBn as $i => $n): ?><option value="<?= $i ?>"<?= $fb === $i ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
<select name="kind"><option value="">Every kind</option><?php foreach (DBK_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= ($_GET['kind'] ?? '') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select><button class="btn sm">Show</button>
<a class="btn sm ghost" href="?<?= h(http_build_query(['view' => 'csv', 'from' => $from, 'to' => $tod, 'branch' => $fb])) ?>">CSV</a></form>
<div class="card"><table><tr><th>Date</th><th>Branch</th><th></th><th>Amount</th><th>By</th></tr><?php foreach ($rows as $e): ?><tr><td><?= h($e['day']) ?></td><td><?= h($allBn[$e['branch']] ?? '') ?></td><td><a href="?view=entry&id=<?= $e['id'] ?>"><?= h(dbk_summary($e['kind'], $e['data'] + ['party' => $e['party'], 'amount' => $e['amount']])) ?></a></td><td><?= $m($e['amount']) ?></td><td><?= h($e['by']) ?></td></tr><?php endforeach; ?></table><?php if (!$rows): ?><p class="muted">Nothing in these dates.</p><?php endif; ?></div>

<?php elseif ($view === 'report' && dbk_is_viewer($c)): $R = dbk_report($db, $c, $from, $tod, $fb && dbk_view_ok($c, $fb) ? $fb : 0); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="report"><input type="date" name="from" value="<?= h($from) ?>"><input type="date" name="to" value="<?= h($tod) ?>">
<select name="branch"><option value="0">All my branches</option><?php foreach ($sheetBn as $i => $n): if (dbk_view_ok($c, $i)): ?><option value="<?= $i ?>"<?= $fb === $i ? ' selected' : '' ?>><?= h($n) ?></option><?php endif; endforeach; ?></select><button class="btn sm">Show</button>
<a class="btn sm ghost" href="?<?= h(http_build_query(['view' => 'csv', 'from' => $from, 'to' => $tod, 'branch' => $fb])) ?>">CSV</a></form>
<div class="card"><h2>Branch-wise</h2><table><tr><th>Branch</th><th>Bills</th><th>Bill amount</th><th>Dispatches</th><th>Received</th><th>With employees</th><th>Petty cash</th><th>Freight</th><th>Days open / closed / checked</th><th>Cash difference</th></tr>
<?php foreach ($R['totals']['branches'] as $i => $t): $d = $R['days'][$i] ?? ['open' => 0, 'closed' => 0, 'checked' => 0, 'diff' => 0]; ?><tr><td><?= h($allBn[$i] ?? '') ?></td><td><?= $t['bills'] ?></td><td><?= $m($t['billAmt']) ?></td><td><?= $t['dispatches'] ?></td><td><?= $m($t['received']) ?></td><td><?= $m($t['withEmp']) ?></td><td><?= $m($t['petty']) ?></td><td><?= $m($t['freight'] + $t['auto']) ?></td><td><?= $d['open'] ?> / <?= $d['closed'] ?> / <?= $d['checked'] ?></td><td><?= $m($d['diff']) ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Person-wise</h2><table><tr><th>Person</th><th>Entries</th><th>Received</th><th>With them</th><th>Petty cash</th></tr><?php foreach ($R['totals']['people'] as $t): ?><tr><td><?= h($t['name']) ?></td><td><?= $t['entries'] ?></td><td><?= $m($t['received']) ?></td><td><?= $m($t['withEmp']) ?></td><td><?= $m($t['petty']) ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Money still with employees</h2><?php if (!$R['withEmp']): ?><p class="muted">None.</p><?php endif; ?><table><?php foreach ($R['withEmp'] as $e): ?><tr><td><?= h($e['day']) ?> · <?= h($allBn[$e['branch']] ?? '') ?></td><td><?= h($e['party']) ?> · <?= $m($e['amount']) ?> · into <?= h((string)($e['data']['person'] ?? '')) ?></td><td><form method="post" class="row"><?= $hid('settle', $e['id']) ?><input type="hidden" name="back" value="report"><input name="note" placeholder="Note"><button class="btn sm">Settled</button></form></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Bills not dispatched</h2><?php if (!$R['notDispatched']): ?><p class="muted">None.</p><?php endif; ?><table><?php foreach ($R['notDispatched'] as $e): ?><tr><td><?= h($e['day']) ?> · <?= h($allBn[$e['branch']] ?? '') ?></td><td><a href="?view=entry&id=<?= $e['id'] ?>"><?= h(dbk_summary('bill', $e['data'] + ['party' => $e['party'], 'amount' => $e['amount']])) ?></a></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Freight by transporter</h2><table><tr><th></th><th>Dispatches</th><th>Local transport</th><th>Freight paid by us</th><th>Paid by party</th></tr><?php foreach ($R['transporters'] as $k => $t): ?><tr><td><?= h((string)$k) ?></td><td><?= $t['n'] ?></td><td><?= $m($t['auto']) ?></td><td><?= $m($t['ours']) ?></td><td><?= $t['party'] ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($view === 'register' && dbk_is_viewer($c)): $rows = dbk_register($db, $c, $from, $tod, $fb && dbk_view_ok($c, $fb) ? $fb : 0); ?>
<form class="card row"><input type="hidden" name="view" value="register"><input type="date" name="from" value="<?= h($from) ?>"><input type="date" name="to" value="<?= h($tod) ?>"><button class="btn sm">Show</button></form>
<div class="card"><table><tr><th>When</th><th>Who</th><th>Branch · day</th><th>What</th><th>Before → after</th></tr><?php foreach ($rows as $l): ?><tr><td><?= h((string)$l['at']) ?></td><td><?= h($l['by_name']) ?></td><td><?= h($allBn[(int)$l['branch_id']] ?? '') ?> · <?= h((string)$l['day']) ?></td>
<td><?= h($l['act']) ?><?= $l['entry_id'] ? ' <a href="?view=entry&id=' . (int)$l['entry_id'] . '">#' . (int)$l['entry_id'] . '</a>' : '' ?> · <?= h($l['detail']) ?></td>
<td class="muted"><?php $bj = $l['before_j'] ? json_decode((string)$l['before_j'], true) : null; $aj = $l['after_j'] ? json_decode((string)$l['after_j'], true) : null;
    if (is_array($bj) && is_array($aj)) foreach ($aj as $k => $vv) if (($bj[$k] ?? null) != $vv) echo h($k . ': ' . json_encode($bj[$k] ?? null, JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($vv, JSON_UNESCAPED_UNICODE)) . '<br>'; ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<h3>Roles that see every entry of their branches, reports and the register</h3><div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $code => $n): ?><label><input type="checkbox" name="view_roles[]" value="<?= h($code) ?>"<?= in_array($code, (array)$set['view_roles'], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div>
<label>Days back a person may fill in (while the day is open) <input type="number" name="back_days" min="0" max="31" value="<?= (int)$set['back_days'] ?>"></label>
<label>Petty cash categories (one per line) <textarea name="petty_cats" rows="8"><?= h($set['petty_cats']) ?></textarea></label>
<p class="muted">Super Admin and CEO viewers see every branch; other viewers see their ticked branches (none ticked = every branch). Everyone else fills in their own branch and ticked branches and sees only their own entries.</p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
