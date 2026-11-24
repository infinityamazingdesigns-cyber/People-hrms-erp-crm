<?php
// Finance (books) — overview, vouchers (drafts / waiting / posted, one voucher: send on, decide, reverse), new voucher,
// day book, ledgers + chart of accounts, ledger statement, trial balance, setup (registrations, banks, team, approval
// rule, books start / lock), settings. Rules in lib/finance.php.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/finance.php';
$u = module_require('finance');
$db = pdo(); $uid = (int)$u['id']; $R = fin_rights($db, $u); $B = fin_book($db);
$today = date('Y-m-d'); $ind = org('currency') === 'INR';
$view = (string)($_GET['v'] ?? 'home');
$d = fn(string $k, string $def) => fin_date_ok((string)($_GET[$k] ?? '')) ? (string)$_GET[$k] : $def;
$from = $d('from', fin_fy_start($today, (int)$B['fy_start'])); $to = $d('to', $today);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $back = 'index.php?v=' . $view;
    try {
        switch ($a) {
            case 'voucher': $id = fin_voucher_save($db, $R, $uid, $_POST, !empty($_POST['send']), $today);
                $v = fin_voucher($db, $id); flash($v['status'] === 'posted' ? 'Posted as ' . $v['number'] . '.' : ($v['status'] === 'waiting' ? 'Sent for approval.' : 'Saved as a draft.')); $back = 'index.php?v=voucher&id=' . $id; break;
            case 'submit': fin_voucher_submit($db, $R, $uid, $id); flash('Sent on.'); $back = 'index.php?v=voucher&id=' . $id; break;
            case 'decide': fin_voucher_decide($db, $R, $uid, $id, ($_POST['ok'] ?? '') === '1', (string)($_POST['note'] ?? '')); flash(($_POST['ok'] ?? '') === '1' ? 'Approved and posted.' : 'Sent back.'); $back = 'index.php?v=voucher&id=' . $id; break;
            case 'reverse': $n = fin_voucher_reverse($db, $R, $uid, $id, (string)($_POST['reason'] ?? ''), (string)($_POST['date'] ?? $today)); flash('Reversed; the original stays.'); $back = 'index.php?v=voucher&id=' . $n; break;
            case 'seed': fin_need($R, 'masters', 'Your Finance role cannot change the chart.'); $n = fin_seed_groups($db, $uid); flash($n ? "$n groups made." : 'The chart is already there.'); $back = 'index.php?v=ledgers'; break;
            case 'group': fin_group_save($db, $R, $uid, $_POST); flash('Group added.'); $back = 'index.php?v=ledgers'; break;
            case 'ledger': fin_ledger_save($db, $R, $uid, $_POST); flash('Ledger saved.'); $back = 'index.php?v=ledgers'; break;
            case 'bankledgers': $n = fin_bank_ledgers($db, $R, $uid); flash($n ? "$n bank ledgers made." : 'Every bank account has its ledger.'); $back = 'index.php?v=ledgers'; break;
            case 'switch': fin_switch($db, $R, $uid, (string)$_POST['what'], $id, ($_POST['on'] ?? '') === '1', (string)($_POST['reason'] ?? '')); flash('Done.'); $back = 'index.php?v=' . ($_POST['what'] === 'ledger' ? 'ledgers' : 'setup'); break;
            case 'reg': fin_reg_save($db, $R, $uid, $_POST); flash('Registration saved.'); $back = 'index.php?v=setup'; break;
            case 'bank': fin_bank_save($db, $R, $uid, $_POST); flash('Bank account saved; the number is sealed.'); $back = 'index.php?v=setup'; break;
            case 'member': fin_member_save($db, $R, $uid, (int)$_POST['who'], (string)($_POST['role'] ?? '')); flash('Team saved.'); $back = 'index.php?v=setup'; break;
            case 'rule': fin_rule_add($db, $R, $uid, $_POST, $today); flash('Approval rule saved; earlier rules stay as history.'); $back = 'index.php?v=setup'; break;
            case 'books': fin_books_set($db, $R, $uid, (string)($_POST['books_from'] ?? ''), (string)($_POST['locked_upto'] ?? ''), (string)($_POST['reason'] ?? '')); flash('Saved.'); $back = 'index.php?v=setup'; break;
            case 'settings':
                if (!has_role($u, 'SUPERADMIN')) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = fin_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['finance'] ?? ['roles' => module_roles('finance')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['finance', 1, implode(',', $st['roles'] ?: module_roles('finance')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'finance'); flash('Settings saved.'); $back = 'index.php?v=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e instanceof PDOException ? 'Could not save. Please try again.' : $e->getMessage(), 'err'); if ($a === 'voucher') $back = 'index.php?v=new' . ($id ? '&id=' . $id : ''); }
    redirect($back);
}

$m = fn(int $x) => h(fin_amt($x, $ind));
$dc = fn(int $x) => h(fin_drcr($x, $ind));
$hid = fn(string $a, int $id = 0) => csrf_field() . '<input type="hidden" name="a" value="' . h($a) . '"><input type="hidden" name="id" value="' . $id . '">';
$people = $db->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$L = fin_ledgers($db);
$Ls = array_values(array_filter($L, fn($l) => !(int)$l['restricted'] || $R['sens']));

if ($view === 'csv' && in_array($_GET['what'] ?? '', ['daybook', 'tb'], true)) {
    $out = fopen('php://temp', 'w+');
    if ($_GET['what'] === 'daybook') { fputcsv($out, ['Date', 'Number', 'Type', 'Narration', 'Amount']); foreach (fin_day_book($db, $R, $from, $to, (string)($_GET['t'] ?? ''), 2000) as $v) fputcsv($out, [$v['vdate'], $v['number'], FIN_VTYPES[$v['vtype']][0], preg_replace('/^[=+\-@]/', "'$0", (string)$v['narration']), fin_amt((int)$v['total_minor'])]); }
    else { $tb = fin_trial_balance($db, $R, $to); fputcsv($out, ['Group', 'Ledger', 'Dr', 'Cr']); foreach ($tb['groups'] as $g) foreach ($g['rows'] as [$n, , $b]) fputcsv($out, [$g['group'], $n, $b > 0 ? fin_amt($b) : '', $b < 0 ? fin_amt(-$b) : '']); fputcsv($out, ['Total', '', fin_amt($tb['dr']), fin_amt($tb['cr'])]); }
    rewind($out); audit($uid, 'fin_export', (string)$_GET['what']);
    header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store'); header('Content-Disposition: attachment; filename="finance-' . $_GET['what'] . '-' . $to . '.csv"');
    echo "\xEF\xBB\xBF" . stream_get_contents($out); exit;
}

$tab = fn(string $k, string $l, int $n = 0) => ' <a class="btn sm' . ($view === $k ? '' : ' ghost') . '" href="?v=' . $k . '">' . h($l) . ($n ? ' <b>' . $n . '</b>' : '') . '</a>';
$vlink = fn(array $v) => '<a href="?v=voucher&id=' . (int)$v['id'] . '">' . h($v['number'] ?: (FIN_VSTATUS[$v['status']] . ' #' . $v['id'])) . '</a>';
$vrow = fn(array $v) => '<tr><td>' . $vlink($v) . '</td><td>' . h($v['vdate']) . '</td><td>' . h(FIN_VTYPES[$v['vtype']][0] ?? '') . '</td><td>' . h(mb_strimwidth((string)$v['narration'], 0, 80, '…')) . ($v['reversed_by_id'] ? ' <span class="pill">reversed</span>' : '') . '</td><td style="text-align:right">' . $m((int)$v['total_minor']) . '</td></tr>';
$waitMine = fin_waiting($db, $u);
$dates = fn(string $v, string $extra = '') => '<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="v" value="' . $v . '">' . $extra . '<label>From <input type="date" name="from" value="' . h($from) . '"></label><label>To <input type="date" name="to" value="' . h($to) . '"></label><button class="btn sm">Show</button></form>';
ui_head('Finance', $u, 'finance'); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('home', 'Overview') ?><?= $tab('vouchers', 'Vouchers', $waitMine) ?><?= $R['enter'] ? $tab('new', 'New voucher') : '' ?><?= $tab('daybook', 'Day book') ?>
<?= $tab('ledgers', 'Ledgers') ?><?= $tab('tb', 'Trial balance') ?><?= $tab('setup', 'Setup') ?><?= has_role($u, 'SUPERADMIN') ? $tab('settings', 'Settings') : '' ?></nav>

<?php if ($view === 'home'): $D = fin_dash($db, $R, $uid, $today, (int)$B['fy_start']); $todo = $R['setup'] ? fin_todo($db) : []; ?>
<?php if ($todo): ?><div class="card"><h2>Still to set up</h2><ul><?php foreach ($todo as $t): ?><li><?= h($t) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="grid">
<div class="card"><div class="muted">Bank and cash</div><h2><?= $dc($D['cash_total']) ?></h2></div>
<div class="card"><div class="muted">Waiting for you</div><h2><?= (int)$D['waiting_mine'] ?></h2><div class="muted"><?= (int)$D['waiting'] ?> waiting in all</div></div>
<div class="card"><div class="muted">Posted this month · this year</div><h2><?= (int)$D['posted_month'] ?> · <?= (int)$D['posted_fy'] ?></h2></div>
<div class="card"><div class="muted">Drafts</div><h2><?= (int)$D['drafts'] ?></h2></div>
<div class="card"><div class="muted">Trial balance</div><h2><?= $D['tb_ok'] ? 'Agrees' : 'Off by ' . $m(abs($D['tb_diff'])) ?></h2><div class="muted">Openings and posted vouchers</div></div>
</div>
<div class="grid">
<div class="card"><h3>Bank and cash ledgers</h3><table><?php foreach ($D['cash'] as [$n, $lid, $b]): ?><tr><td><a href="?v=statement&id=<?= $lid ?>"><?= h($n) ?></a></td><td style="text-align:right"><?= $dc($b) ?></td></tr><?php endforeach; ?></table><?php if (!$D['cash']): ?><p class="muted">No bank or cash ledger yet.</p><?php endif; ?></div>
<div class="card"><h3>This month by type</h3><table><?php foreach (FIN_VTYPES as $k => [$l]): [$n, $t] = $D['by_type'][$k] ?? [0, 0]; ?><tr><td><?= h($l) ?></td><td><?= $n ?></td><td style="text-align:right"><?= $m($t) ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h3>Recently posted</h3><table><?php foreach ($D['recent'] as $v) echo $vrow($v); ?></table><?php if (!$D['recent']): ?><p class="muted">Nothing posted this year.</p><?php endif; ?></div>
</div>

<?php elseif ($view === 'vouchers'): ?>
<?php foreach (['waiting' => 'Waiting for approval', 'draft' => 'Drafts', 'posted' => 'Posted (latest)'] as $st => $ttl): $rows = fin_voucher_list($db, $R, $uid, $st, $st === 'posted' ? 50 : 200); ?>
<div class="card"><h2><?= h($ttl) ?></h2><?php if (!$rows): ?><p class="muted">None.</p><?php else: ?><div class="tbl-wrap"><table><?php foreach ($rows as $v) echo $vrow($v); ?></table></div><?php endif; ?></div>
<?php endforeach; ?>

<?php elseif ($view === 'new' && $R['enter']):
    $v = isset($_GET['id']) ? fin_voucher($db, (int)$_GET['id']) : null; if ($v && $v['status'] !== 'draft') $v = null;
    $lines = $v ? $v['lines'] : []; $rows = max(4, count($lines) + 2); $regs = fin_regs($db); ?>
<form method="post" class="card"><?= $hid('voucher', (int)($v['id'] ?? 0)) ?><input type="hidden" name="request_id" value="<?= h(bin2hex(random_bytes(8))) ?>">
<h2><?= $v ? 'Change draft #' . (int)$v['id'] : 'New voucher' ?></h2>
<div class="grid"><label>Type <select name="vtype"><?php foreach (FIN_VTYPES as $k => [$l]): ?><option value="<?= $k ?>"<?= ($v['vtype'] ?? '') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
<label>Date <input type="date" name="vdate" max="<?= $today ?>" value="<?= h($v['vdate'] ?? $today) ?>" required></label>
<?php if ($regs): ?><label>Registration <select name="reg_id"><option value="">—</option><?php foreach ($regs as $r): ?><option value="<?= (int)$r['id'] ?>"<?= (int)($v['reg_id'] ?? 0) === (int)$r['id'] ? ' selected' : '' ?>><?= h(($r['number'] ?: 'Pending') . ' · ' . $r['region']) ?></option><?php endforeach; ?></select></label><?php endif; ?></div>
<div class="tbl-wrap"><table id="finLines"><tr><th>Ledger</th><th>Dr</th><th>Cr</th><th>Line note</th></tr>
<?php for ($i = 0; $i < $rows; $i++): $ln = $lines[$i] ?? null; ?><tr><td><select name="line_ledger[]" aria-label="Ledger"><option value="0">—</option><?php foreach ($Ls as $l): ?><option value="<?= (int)$l['id'] ?>"<?= (int)($ln['ledger_id'] ?? 0) === (int)$l['id'] ? ' selected' : '' ?>><?= h($l['name'] . ' · ' . $l['group_name']) ?></option><?php endforeach; ?></select></td>
<td><input name="line_dr[]" inputmode="decimal" aria-label="Dr" value="<?= $ln && (int)$ln['dr_minor'] ? h(fin_amt((int)$ln['dr_minor'])) : '' ?>"></td><td><input name="line_cr[]" inputmode="decimal" aria-label="Cr" value="<?= $ln && (int)$ln['cr_minor'] ? h(fin_amt((int)$ln['cr_minor'])) : '' ?>"></td>
<td><input name="line_note[]" maxlength="200" aria-label="Line note" value="<?= h($ln['line_note'] ?? '') ?>"></td></tr><?php endfor; ?></table></div>
<p class="muted" id="finDiff"></p><button type="button" class="btn sm ghost" id="finAdd">Add a line</button>
<label>Narration <input name="narration" maxlength="500" value="<?= h($v['narration'] ?? '') ?>"></label>
<div class="row"><button class="btn" name="send" value="1">Send on (post or approval)</button> <button class="btn ghost">Save as draft</button></div></form>
<script>(function(){var t=document.getElementById('finLines'),o=document.getElementById('finDiff');
function n(s){s=(s||'').replace(/[,\s]/g,'');return /^\d+(\.\d{1,2})?$/.test(s)?Math.round(parseFloat(s)*100):0}
function c(){var d=0,k=0;t.querySelectorAll('tr').forEach(function(r){var a=r.querySelector('[name="line_dr[]"]'),b=r.querySelector('[name="line_cr[]"]');if(a){d+=n(a.value);k+=n(b.value)}});o.textContent=d||k?(d===k?'Dr = Cr ('+(d/100).toFixed(2)+')':'Difference '+(Math.abs(d-k)/100).toFixed(2)):''}
t.addEventListener('input',c);document.getElementById('finAdd').onclick=function(){var r=t.rows[t.rows.length-1].cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value=''});r.querySelector('select').value='0';t.appendChild(r)};c()})();</script>

<?php elseif ($view === 'voucher' && ($v = fin_voucher($db, (int)($_GET['id'] ?? 0))) && (!(int)$v['restricted'] || $R['sens'])): $mine = (int)$v['created_by'] === $uid; ?>
<div class="card"><h2><?= h($v['number'] ?: FIN_VTYPES[$v['vtype']][0] . ' #' . $v['id']) ?> · <?= h(FIN_VSTATUS[$v['status']] ?? $v['status']) ?></h2>
<p><?= h(FIN_VTYPES[$v['vtype']][0]) ?> · <?= h($v['vdate']) ?> · made by <?= h($people[(int)$v['created_by']] ?? '—') ?><?= $v['posted_by'] ? ' · posted by ' . h($people[(int)$v['posted_by']] ?? '—') : '' ?></p>
<?php if ($v['narration'] !== ''): ?><p><?= h($v['narration']) ?></p><?php endif; ?>
<?php if ($v['decide_note']): ?><p class="muted">Note: <?= h($v['decide_note']) ?></p><?php endif; ?>
<?php if ($v['reverses_id']): ?><p>Reverses <a href="?v=voucher&id=<?= (int)$v['reverses_id'] ?>">voucher #<?= (int)$v['reverses_id'] ?></a>.</p><?php endif; ?>
<?php if ($v['reversed_by_id']): ?><p><b>Reversed</b> by <a href="?v=voucher&id=<?= (int)$v['reversed_by_id'] ?>">voucher #<?= (int)$v['reversed_by_id'] ?></a> — <?= h((string)$v['reverse_reason']) ?></p><?php endif; ?>
<div class="tbl-wrap"><table><tr><th>Ledger</th><th style="text-align:right">Dr</th><th style="text-align:right">Cr</th><th>Note</th></tr>
<?php foreach ($v['lines'] as $ln): ?><tr><td><?= h($ln['ledger_name']) ?></td><td style="text-align:right"><?= (int)$ln['dr_minor'] ? $m((int)$ln['dr_minor']) : '' ?></td><td style="text-align:right"><?= (int)$ln['cr_minor'] ? $m((int)$ln['cr_minor']) : '' ?></td><td><?= h($ln['line_note']) ?></td></tr><?php endforeach; ?>
<tr><th>Total</th><th style="text-align:right"><?= $m((int)$v['total_minor']) ?></th><th style="text-align:right"><?= $m((int)$v['total_minor']) ?></th><th></th></tr></table></div></div>
<?php if ($v['status'] === 'draft' && $R['enter'] && ($mine || $R['post'])): ?>
<div class="card row"><a class="btn ghost" href="?v=new&id=<?= (int)$v['id'] ?>">Change</a><form method="post"><?= $hid('submit', (int)$v['id']) ?><button class="btn">Send on</button></form></div>
<?php elseif ($v['status'] === 'waiting'): ?>
<div class="card"><p class="muted">Waiting for <?= $v['need'] === 'approver' ? h($people[(int)$v['approver_id']] ?? 'the setup roles') . ' (at or above the approval limit)' : 'an Accounts head / manager' ?>.</p>
<?php if (fin_can_decide($R, $uid, $v)): ?><form method="post" class="row" style="flex-wrap:wrap"><?= $hid('decide', (int)$v['id']) ?><label>Note <input name="note" maxlength="300"></label><button class="btn" name="ok" value="1">Approve and post</button> <button class="btn ghost" name="ok" value="0">Send back (note needed)</button></form>
<?php elseif ($mine): ?><p class="muted">Someone else decides a voucher you made.</p><?php endif; ?></div>
<?php elseif ($v['status'] === 'posted' && $R['post'] && !$v['reversed_by_id'] && !$v['reverses_id']): ?>
<form method="post" class="card row" style="flex-wrap:wrap"><?= $hid('reverse', (int)$v['id']) ?><label>Reversal date <input type="date" name="date" value="<?= $today ?>" max="<?= $today ?>"></label><label>Reason <input name="reason" maxlength="300" required></label><button class="btn ghost">Reverse (the original stays)</button></form>
<?php endif; ?>

<?php elseif ($view === 'daybook'): $vt = (string)($_GET['t'] ?? ''); $rows = fin_day_book($db, $R, $from, $to, $vt); ?>
<?= $dates('daybook', '<select name="t" aria-label="Type"><option value="">Every type</option>' . implode('', array_map(fn($k, $x) => '<option value="' . $k . '"' . ($vt === $k ? ' selected' : '') . '>' . h($x[0]) . '</option>', array_keys(FIN_VTYPES), FIN_VTYPES)) . '</select>') ?>
<div class="card"><p><a class="btn sm ghost" href="?<?= h(http_build_query(['v' => 'csv', 'what' => 'daybook', 'from' => $from, 'to' => $to, 't' => $vt])) ?>">CSV</a></p><?php if (!$rows): ?><p class="muted">Nothing posted in these dates.</p><?php else: ?><div class="tbl-wrap"><table><?php foreach ($rows as $v) echo $vrow($v); ?></table></div><?php endif; ?></div>

<?php elseif ($view === 'statement' && ($lid = (int)($_GET['id'] ?? 0))): try { $S = fin_ledger_statement($db, $R, $lid, $from, $to); } catch (Throwable $e) { $S = null; } ?>
<?php if (!$S): ?><div class="card">Not available.</div><?php else: ?>
<?= $dates('statement', '<input type="hidden" name="id" value="' . $lid . '">') ?>
<div class="card"><h2><?= h($S['ledger']['name']) ?> <span class="muted"><?= h($S['ledger']['group_name']) ?></span></h2>
<div class="tbl-wrap"><table><tr><th>Date</th><th>Voucher</th><th>Narration</th><th style="text-align:right">Dr</th><th style="text-align:right">Cr</th><th style="text-align:right">Balance</th></tr>
<tr><td><?= h($from) ?></td><td colspan="4">Opening</td><td style="text-align:right"><?= $dc($S['open']) ?></td></tr>
<?php foreach ($S['rows'] as $r): ?><tr><td><?= h($r['vdate']) ?></td><td><a href="?v=voucher&id=<?= (int)$r['id'] ?>"><?= h($r['number']) ?></a></td><td><?= h(trim($r['narration'] . ' ' . $r['line_note'])) ?></td><td style="text-align:right"><?= (int)$r['dr_minor'] ? $m((int)$r['dr_minor']) : '' ?></td><td style="text-align:right"><?= (int)$r['cr_minor'] ? $m((int)$r['cr_minor']) : '' ?></td><td style="text-align:right"><?= $dc((int)$r['bal']) ?></td></tr><?php endforeach; ?>
<tr><th colspan="3">Closing</th><th style="text-align:right"><?= $m($S['dr']) ?></th><th style="text-align:right"><?= $m($S['cr']) ?></th><th style="text-align:right"><?= $dc($S['close']) ?></th></tr></table></div></div>
<?php endif; ?>

<?php elseif ($view === 'tb'): $tb = fin_trial_balance($db, $R, $to); ?>
<form class="card row"><input type="hidden" name="v" value="tb"><label>As at <input type="date" name="to" value="<?= h($to) ?>"></label><button class="btn sm">Show</button> <a class="btn sm ghost" href="?<?= h(http_build_query(['v' => 'csv', 'what' => 'tb', 'to' => $to])) ?>">CSV</a></form>
<div class="card"><div class="tbl-wrap"><table><tr><th>Group / ledger</th><th style="text-align:right">Dr</th><th style="text-align:right">Cr</th></tr>
<?php foreach ($tb['groups'] as $g): ?><tr><th><?= h($g['group']) ?> <span class="muted"><?= h(FIN_NATURES[$g['nature']] ?? '') ?></span></th><th style="text-align:right"><?= $g['dr'] ? $m($g['dr']) : '' ?></th><th style="text-align:right"><?= $g['cr'] ? $m($g['cr']) : '' ?></th></tr>
<?php foreach ($g['rows'] as [$n, $lid, $b]): ?><tr><td style="padding-left:18px"><?= $lid ? '<a href="?v=statement&id=' . $lid . '">' . h($n) . '</a>' : h($n) ?></td><td style="text-align:right"><?= $b > 0 ? $m($b) : '' ?></td><td style="text-align:right"><?= $b < 0 ? $m(-$b) : '' ?></td></tr><?php endforeach; endforeach; ?>
<tr><th>Total</th><th style="text-align:right"><?= $m($tb['dr']) ?></th><th style="text-align:right"><?= $m($tb['cr']) ?></th></tr></table></div>
<?php if ($tb['opening_diff'] !== 0): ?><p class="err">Opening balances do not agree: off by <?= $dc($tb['opening_diff']) ?>. Correct the openings on the Ledgers screen.</p><?php endif; ?></div>

<?php elseif ($view === 'ledgers'): $G = fin_groups($db); $LA = fin_ledgers($db, true); $banks = fin_banks($db); $regs = fin_regs($db); $edit = null;
    foreach ($LA as $l) if ((int)$l['id'] === (int)($_GET['id'] ?? 0) && (!(int)$l['restricted'] || $R['sens'])) $edit = $l; ?>
<?php if (!$G): ?><div class="card"><p>No chart of accounts yet.</p><?php if ($R['masters']): ?><form method="post"><?= $hid('seed') ?><button class="btn">Make the standard groups</button></form><?php endif; ?></div><?php else: ?>
<?php if ($R['masters']): ?>
<form method="post" class="card"><?= $hid('ledger', (int)($edit['id'] ?? 0)) ?><h2><?= $edit ? 'Change ' . h($edit['name']) : 'Add a ledger' ?></h2><div class="grid">
<label>Name <input name="name" maxlength="160" required value="<?= h($edit['name'] ?? '') ?>"></label>
<label>Group <select name="group_id"><?php foreach ($G as $g): ?><option value="<?= (int)$g['id'] ?>"<?= (int)($edit['group_id'] ?? 0) === (int)$g['id'] ? ' selected' : '' ?>><?= h(($g['parent_id'] ? '— ' : '') . $g['name']) ?></option><?php endforeach; ?></select></label>
<label>Opening balance <input name="opening" inputmode="decimal" value="<?= $edit && (int)$edit['opening_minor'] ? h(fin_amt(abs((int)$edit['opening_minor']))) : '' ?>"></label>
<label>Side <select name="opening_side"><option value="dr">Dr</option><option value="cr"<?= $edit && (int)$edit['opening_minor'] < 0 ? ' selected' : '' ?>>Cr</option></select></label>
<?php if ($banks): ?><label>Bank account <select name="bank_id"><option value="">—</option><?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)($edit['bank_id'] ?? 0) === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['bank'] . ' ··' . $b['last4']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php if ($regs): ?><label>Registration <select name="reg_id"><option value="">—</option><?php foreach ($regs as $r): ?><option value="<?= (int)$r['id'] ?>"<?= (int)($edit['reg_id'] ?? 0) === (int)$r['id'] ? ' selected' : '' ?>><?= h(($r['number'] ?: 'Pending') . ' · ' . $r['region']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label>Note <input name="note" maxlength="300" value="<?= h($edit['note'] ?? '') ?>"></label></div>
<?php if ($R['setup']): ?><label><input type="checkbox" name="restricted" value="1"<?= !empty($edit['restricted']) ? ' checked' : '' ?>> Restricted (salary and the like: only the restricted-ledger roles see it; others see one total line)</label><?php endif; ?>
<div class="row"><button class="btn">Save</button><?php if ($edit): ?> <a class="btn ghost" href="?v=ledgers">Cancel</a><?php endif; ?></div></form>
<div class="card row" style="flex-wrap:wrap"><form method="post" class="row"><?= $hid('group') ?><label>New group <input name="name" maxlength="120"></label><label>under <select name="parent_id"><?php foreach ($G as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?></select></label><button class="btn sm ghost">Add group</button></form>
<form method="post"><?= $hid('bankledgers') ?><button class="btn sm ghost">Make ledgers for bank accounts</button></form></div>
<?php endif; ?>
<div class="card"><div class="tbl-wrap"><table><tr><th>Ledger</th><th>Group</th><th style="text-align:right">Opening</th><th></th></tr>
<?php foreach ($LA as $l): if ((int)$l['restricted'] && !$R['sens']) continue; ?><tr<?= (int)$l['active'] ? '' : ' class="muted"' ?>><td><a href="?v=statement&id=<?= (int)$l['id'] ?>"><?= h($l['name']) ?></a><?= (int)$l['restricted'] ? ' <span class="pill">restricted</span>' : '' ?><?= (int)$l['active'] ? '' : ' (off: ' . h((string)$l['off_reason']) . ')' ?></td><td><?= h($l['group_name']) ?></td><td style="text-align:right"><?= $dc((int)$l['opening_minor']) ?></td>
<td><?php if ($R['masters']): ?><a class="btn sm ghost" href="?v=ledgers&id=<?= (int)$l['id'] ?>">Change</a> <form method="post" class="row" style="display:inline-flex"><?= $hid('switch', (int)$l['id']) ?><input type="hidden" name="what" value="ledger"><input type="hidden" name="on" value="<?= (int)$l['active'] ? '0' : '1' ?>"><?php if ((int)$l['active']): ?><input name="reason" placeholder="Reason" aria-label="Reason to switch off"><?php endif; ?><button class="btn sm ghost"><?= (int)$l['active'] ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div></div>
<?php endif; ?>

<?php elseif ($view === 'setup'): $regs = fin_regs($db, true); $banks = fin_banks($db, true); $rules = fin_rules($db); $team = fin_members($db); $rule = fin_rule_on($rules, $today); $brs = array_column(branches(false), 'name', 'id'); $tl = crm_tax_label(); ?>
<div class="card"><h2>Tax registrations (<?= h($tl) ?>)</h2><div class="tbl-wrap"><table><tr><th>Number</th><th>Region</th><th>Branches</th><th></th></tr>
<?php foreach ($regs as $r): ?><tr<?= (int)$r['active'] ? '' : ' class="muted"' ?>><td><?= $r['number'] ? h($r['number']) : '<span class="pill">Pending</span>' ?></td><td><?= h($r['region']) ?></td><td><?= h(implode(', ', array_map(fn($i) => $brs[(int)$i] ?? '', array_filter(explode(',', (string)$r['branches']))))) ?></td>
<td><?php if ($R['setup']): ?><form method="post" class="row"><?= $hid('switch', (int)$r['id']) ?><input type="hidden" name="what" value="reg"><input type="hidden" name="on" value="<?= (int)$r['active'] ? '0' : '1' ?>"><?php if ((int)$r['active']): ?><input name="reason" placeholder="Reason" aria-label="Reason"><?php endif; ?><button class="btn sm ghost"><?= (int)$r['active'] ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>
<?php if ($R['setup']): ?><form method="post" class="grid"><?= $hid('reg') ?><label><?= h($tl) ?> (blank while waiting) <input name="number" maxlength="30"></label><label>Region <input name="region" maxlength="60"></label><label>Address <input name="address" maxlength="500"></label>
<label>Branches <select name="branches[]" multiple size="3"><?php foreach ($brs as $i => $n): ?><option value="<?= (int)$i ?>"><?= h($n) ?></option><?php endforeach; ?></select></label><label>Note <input name="note" maxlength="300"></label><div><button class="btn sm">Add registration</button></div></form><?php endif; ?></div>

<div class="card"><h2>Bank accounts</h2><div class="tbl-wrap"><table><tr><th>Bank</th><th>Kind</th><th>Account</th><th>Code</th><th></th></tr>
<?php foreach ($banks as $b): ?><tr<?= (int)$b['active'] ? '' : ' class="muted"' ?>><td><?= h($b['bank']) ?><?= (int)$b['is_main'] ? ' <span class="pill">main</span>' : '' ?></td><td><?= h(FIN_BANK_KINDS[$b['kind']] ?? $b['kind']) ?></td><td>··<?= h($b['last4']) ?></td><td><?= h($b['code']) ?></td>
<td><?php if ($R['setup']): ?><form method="post" class="row"><?= $hid('switch', (int)$b['id']) ?><input type="hidden" name="what" value="bank"><input type="hidden" name="on" value="<?= (int)$b['active'] ? '0' : '1' ?>"><?php if ((int)$b['active']): ?><input name="reason" placeholder="Reason" aria-label="Reason"><?php endif; ?><button class="btn sm ghost"><?= (int)$b['active'] ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>
<?php if ($R['setup']): ?><form method="post" class="grid" autocomplete="off"><?= $hid('bank') ?><label>Bank <input name="bank" maxlength="120" required></label><label>Kind <select name="kind"><?php foreach (FIN_BANK_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
<label>Account number (sealed) <input name="account" maxlength="40" required autocomplete="off"></label><label>Branch / routing code <input name="code" maxlength="20"></label><label>Bank branch <input name="branch_name" maxlength="120"></label>
<label>Settles into <select name="settles_into"><option value="">—</option><?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['bank'] . ' ··' . $b['last4']) ?></option><?php endforeach; ?></select></label>
<label><input type="checkbox" name="is_main" value="1"> Main account</label><div><button class="btn sm">Add bank account</button></div></form><?php endif; ?></div>

<div class="card"><h2>Voucher approval rule</h2><p><?= $rule ? 'At or above <b>' . $m((int)$rule['limit_minor']) . '</b> a voucher waits for <b>' . h($people[(int)$rule['approver_id']] ?? '—') . '</b> (from ' . h($rule['from_date']) . '). Below it, an Accounts head / manager posts; an executive\'s voucher waits for their check.' : 'No rule yet: every voucher waits for the setup roles.' ?></p>
<?php if ($R['setup']): ?><form method="post" class="row" style="flex-wrap:wrap"><?= $hid('rule') ?><label>Limit <input name="limit" inputmode="decimal" required></label><label>Approver <select name="approver_id"><?php foreach ($people as $i => $n): ?><option value="<?= (int)$i ?>"><?= h($n) ?></option><?php endforeach; ?></select></label><label>From <input type="date" name="from_date" min="<?= $today ?>" value="<?= $today ?>"></label><label>Note <input name="note" maxlength="300"></label><button class="btn sm">Save rule</button></form><?php endif; ?>
<?php if (count($rules) > 1): ?><details><summary>Earlier rules</summary><table><?php foreach ($rules as $r): ?><tr><td><?= h($r['from_date']) ?></td><td><?= $m((int)$r['limit_minor']) ?></td><td><?= h($people[(int)$r['approver_id']] ?? '—') ?></td></tr><?php endforeach; ?></table></details><?php endif; ?></div>

<div class="card"><h2>Finance team</h2><table><?php foreach ($team as $t): ?><tr<?= (int)$t['active'] ? '' : ' class="muted"' ?>><td><?= h($t['name']) ?></td><td><?= (int)$t['active'] ? h(FIN_ROLES[$t['role']] ?? $t['role']) : 'Removed' ?></td></tr><?php endforeach; ?></table>
<p class="muted">Holders of the <?= h(role_labels()[fin_settings()['team_role']] ?? 'team') ?> role with no row here work as executives. Nobody sets their own role.</p>
<?php if ($R['setup']): ?><form method="post" class="row" style="flex-wrap:wrap"><?= $hid('member') ?><label>Person <select name="who"><?php foreach ($people as $i => $n): if ((int)$i !== $uid): ?><option value="<?= (int)$i ?>"><?= h($n) ?></option><?php endif; endforeach; ?></select></label><label>Role <select name="role"><option value="">Remove</option><?php foreach (FIN_ROLES as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label><button class="btn sm">Save</button></form><?php endif; ?></div>

<div class="card"><h2>Books start and lock</h2><p>Books start <b><?= h((string)($B['books_from'] ?: 'not set')) ?></b> · locked up to <b><?= h((string)($B['locked_upto'] ?: 'nothing locked')) ?></b> · year starts in month <?= (int)$B['fy_start'] ?> (Organisation settings)</p>
<?php if ($R['lock']): ?><form method="post" class="row" style="flex-wrap:wrap"><?= $hid('books') ?><label>Books start <input type="date" name="books_from" value="<?= h((string)$B['books_from']) ?>"></label><label>Locked up to <input type="date" name="locked_upto" value="<?= h((string)$B['locked_upto']) ?>"></label><label>Reason (to open a locked period) <input name="reason" maxlength="300"></label><button class="btn sm">Save</button></form><?php endif; ?></div>

<?php elseif ($view === 'settings' && has_role($u, 'SUPERADMIN')): $set = fin_settings(); $roles = role_labels(); ?>
<form method="post" class="card"><?= $hid('settings') ?><h2>Settings</h2>
<?php foreach (['setup_roles' => 'Change the setup, approve over the limit when no approver is set, lock the books', 'restricted_roles' => 'See restricted ledgers'] as $k => $l): ?><fieldset><legend><?= h($l) ?></legend><?php foreach ($roles as $c => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($c) ?>"<?= in_array($c, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label> <?php endforeach; ?></fieldset><?php endforeach; ?>
<label>Role that works as Accounts executive <select name="team_role"><option value="">None</option><?php foreach ($roles as $c => $n): ?><option value="<?= h($c) ?>"<?= $set['team_role'] === $c ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
<button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
