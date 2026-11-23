<?php
// Production workers — hours grid, piece-rate work, workers, pay run, advances, piece rates, settings, change log.
// Rules in lib/production.php. Entry roles see hours and pieces only, never a rate, wage or advance.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/production.php';
$u = module_require('production');
$db = pdo(); $x = prd_ctx($u); $R = $x['R']; $set = $x['set']; $uid = (int)$u['id']; $isAdmin = has_role($u, 'SUPERADMIN'); $today = date('Y-m-d');
$ym = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_REQUEST['ym'] ?? '')) ? (string)$_REQUEST['ym'] : date('Y-m');
$view = (string)($_GET['view'] ?? 'hours');
if (!$R['view']) { ui_head('Production workers', $u, 'production'); echo '<div class="card">Your role has no access here.</div>'; ui_foot(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $back = 'index.php?view=' . urlencode((string)($_POST['back'] ?? $view)) . '&ym=' . $ym;
    try {
        switch ($a) {
            case 'hours': flash(prd_hours_save($db, $x, $ym, (array)($_POST['h'] ?? []), $today) . ' cells saved.'); $back = 'index.php?view=hours&ym=' . $ym; break;
            case 'pieces': flash(prd_pieces_add($db, $x, (string)($_POST['d'] ?? ''), (int)($_POST['type_id'] ?? 0), (array)($_POST['codes'] ?? []), (int)($_POST['qty'] ?? 0), (string)($_POST['note'] ?? ''), $today) . ' line(s) added.'); $back = 'index.php?view=pieces&ym=' . $ym; break;
            case 'pieceoff': prd_piece_remove($db, $x, (int)($_POST['id'] ?? 0), (string)($_POST['why'] ?? '')); flash('Taken off.'); $back = 'index.php?view=pieces&ym=' . $ym; break;
            case 'worker': $code = prd_worker_save($db, $x, $_POST, (string)($_POST['code'] ?? '')); flash('Saved ' . $code . '.'); $back = 'index.php?view=worker&code=' . urlencode($code); break;
            case 'rate': prd_rate_add($db, $x, (string)($_POST['code'] ?? ''), (string)($_POST['from'] ?? ''), (string)($_POST['amount'] ?? ''), (string)($_POST['note'] ?? '')); flash('Rate added.'); $back = 'index.php?view=worker&code=' . urlencode((string)$_POST['code']); break;
            case 'type': prd_piece_type_save($db, $x, (int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), !empty($_POST['active'])); flash('Saved.'); $back = 'index.php?view=rates'; break;
            case 'prate': prd_piece_rate_add($db, $x, (int)($_POST['id'] ?? 0), (string)($_POST['from'] ?? ''), (string)($_POST['amount'] ?? '')); flash('Rate added.'); $back = 'index.php?view=rates'; break;
            case 'adv': prd_adv_add($db, $x, $_POST, $today); flash('Added.'); $back = 'index.php?view=adv'; break;
            case 'advoff': prd_adv_remove($db, $x, (int)($_POST['id'] ?? 0), (string)($_POST['why'] ?? '')); flash('Taken off.'); $back = 'index.php?view=adv'; break;
            case 'inputs': flash(prd_inputs_save($db, $x, $ym, (array)($_POST['in'] ?? [])) . ' worker(s) changed.'); $back = 'index.php?view=pay&ym=' . $ym; break;
            case 'confirm': prd_month_confirm($db, $x, $ym, $today); flash('Confirmed and locked.'); $back = 'index.php?view=pay&ym=' . $ym; break;
            case 'reopen': prd_month_reopen($db, $x, $ym, (string)($_POST['why'] ?? '')); flash('Reopened.'); $back = 'index.php?view=pay&ym=' . $ym; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = prd_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['production'] ?? ['roles' => module_roles('production')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['production', 1, implode(',', $st['roles'] ?: module_roles('production')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'production'); flash('Settings saved.'); $back = 'index.php?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'worker') { $_SESSION['prd_form'] = $_POST; $back = 'index.php?view=' . ((string)($_POST['code'] ?? '') !== '' ? 'worker&code=' . urlencode((string)$_POST['code']) : 'workers'); } }
    redirect($back);
}
$W = prd_workers($db, $x); $bn = array_column(branches(false), 'name', 'id'); $M = prd_month($db, $ym); $locked = $M['status'] === 'confirmed';
$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$names = array_map(fn($w) => $w['name'] . ' · ' . $w['code'], array_filter($W, fn($w) => (int)$w['active']));
$cur = org('currency') ?: '';
if ($view === 'csv' && $R['money']) {
    $rows = prd_month_rows($db, $x, $ym, true); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="production-pay-' . $ym . '.csv"');
    $dl = $set['ded_labels'];
    echo "\xEF\xBB\xBF" . implode(',', array_map('prd_csv_cell', ['Code', 'Name', 'Branch', 'Paid by', 'Days', 'Hours', 'OT hours', 'Pieces', 'Wages', 'OT pay', 'Piece pay', 'Other earnings', 'Gross', 'Advance recovered', $dl[0], $dl[1], $dl[2], 'Other deductions', 'Net', 'Advance left'])) . "\r\n";
    foreach ($rows as $c => $r) { $p = $r['pay']; echo implode(',', array_map('prd_csv_cell', [$c, $r['name'], $bn[$r['branch_id']] ?? '', $r['paid_by'], $p['days'], prd_hrs($p['worked']), prd_hrs($p['ot']), $p['pcs'], prd_money($p['reg_pay']), prd_money($p['ot_pay']), prd_money($p['pc_pay']), prd_money($p['in']['other_earn']), prd_money($p['gross']),
        prd_money($p['in']['adv_recover']), prd_money($p['in']['ded_a']), prd_money($p['in']['ded_b']), prd_money($p['in']['ded_c']), prd_money($p['in']['other_ded']), prd_money($p['net']), prd_money($p['adv_after'])])) . "\r\n"; }
    audit($uid, 'prd_export', $ym); exit;
}
ui_head('Production workers', $u, 'production');
$tab = fn(string $k, string $l) => '<a class="btn sm' . ($view === $k ? '' : ' ghost') . '" href="?view=' . $k . '&ym=' . $ym . '">' . h($l) . '</a> ';
$monthBar = fn(string $v) => '<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="' . $v . '">Month <input type="month" name="ym" value="' . h($ym) . '"><button class="btn sm">Show</button>' . ($locked ? ' <b>Confirmed — locked</b>' : '') . '</form>'; ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('hours', 'Hours') . $tab('pieces', 'Piece work') . $tab('workers', 'Workers') . ($R['money'] ? $tab('pay', 'Pay run') . $tab('adv', 'Advances') . $tab('rates', 'Piece rates') : '') . ($R['money'] ? '' : $tab('pay', 'Month totals')) . ($isAdmin ? $tab('settings', 'Settings') . $tab('log', 'Change log') : '') ?></nav>
<?php if ($x['scope'] === []): ?><div class="card">No branch is ticked for your login on Users &amp; access, so no worker shows. Ask the Super Admin.</div><?php endif; ?>

<?php if ($view === 'hours'): $H = prd_hours($db, $ym); $days = prd_days($ym); $act = array_filter($W, fn($w) => (int)$w['active'] || isset($H[$w['code']])); echo $monthBar('hours'); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="hours"><input type="hidden" name="ym" value="<?= h($ym) ?>">
<table style="font-size:.85em"><tr><th>Worker</th><?php foreach ($days as $d): ?><th><?= (int)substr($d, 8) ?><br><span class="muted"><?= h(substr(date('D', strtotime($d)), 0, 2)) ?></span></th><?php endforeach; ?><th>Total</th></tr>
<?php foreach ($act as $c => $w): $tot = array_sum($H[$c] ?? []); ?><tr><td><?= h($w['name']) ?><div class="muted"><?= h($c) ?></div></td>
<?php foreach ($days as $d): $v = $H[$c][$d] ?? 0; ?><td><?php if ($d > $today || $locked || !$R['entry']): ?><?= $v ? h(prd_hrs($v)) : '' ?><?php else: ?><input name="h[<?= h($c) ?>][<?= $d ?>]" value="<?= $v ? h(prd_hrs($v)) : '' ?>" size="3" style="width:3.2em" inputmode="decimal"><?php endif; ?></td><?php endforeach; ?>
<td><b><?= h(prd_hrs($tot)) ?></b></td></tr><?php endforeach; ?><?php if (!$act): ?><tr><td colspan="<?= count($days) + 2 ?>" class="muted">No workers yet.</td></tr><?php endif; ?></table>
<?php if ($R['entry'] && !$locked && $act): ?><button class="btn">Save hours</button> <span class="muted">9.5 or 9:30; blank = not worked. Only changed cells are saved.</span><?php endif; ?></form>

<?php elseif ($view === 'pieces'): $T = prd_piece_types($db, true); $L = prd_pieces($db, $ym, $W, true); echo $monthBar('pieces'); ?>
<?php if ($R['entry'] && !$locked && $T): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="pieces"><input type="hidden" name="ym" value="<?= h($ym) ?>"><h3>Pieces made</h3>
<div class="grid"><label>Day <input type="date" name="d" value="<?= h(min($today, date('Y-m-t', strtotime("$ym-01")))) ?>" max="<?= $today ?>" required></label><label>Type of work <select name="type_id" required><?= $opts(array_column($T, 'name', 'id'), '', '—') ?></select></label>
<label>Pieces <input type="number" name="qty" min="1" required></label><label>Worker (several = a team, pieces split evenly) <select name="codes[]" multiple size="5" required><?= $opts($names) ?></select></label><label>Note <input name="note"></label></div><button class="btn sm">Add</button></form>
<?php elseif ($R['entry'] && !$T): ?><div class="card muted">No piece-rate types yet<?= $R['money'] ? ' — add them on Piece rates.' : '.' ?></div><?php endif; ?>
<div class="card"><table><tr><th>Day</th><th>Worker</th><th>Type</th><th>Pieces</th><th></th></tr>
<?php foreach ($L as $l): ?><tr<?= $l['removed_at'] ? ' class="muted"' : '' ?>><td><?= h($l['d']) ?></td><td><?= h($W[$l['code']]['name'] ?? $l['code']) ?></td><td><?= h($l['type']) ?></td><td><?= (int)$l['qty'] ?></td>
<td><?php if ($l['removed_at']): ?>Off — <?= h($l['removed_why']) ?><?php elseif ($R['entry'] && !$locked): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="pieceoff"><input type="hidden" name="ym" value="<?= h($ym) ?>"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input name="why" placeholder="Why" size="10" required><button class="btn sm ghost">Take off</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$L): ?><tr><td colspan="5" class="muted">Nothing this month.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'workers'): $f = $_SESSION['prd_form'] ?? []; unset($_SESSION['prd_form']); ?>
<div class="card"><table><tr><th>Code</th><th>Name</th><th>Branch</th><th>Department</th><th>Skill</th><th></th></tr>
<?php foreach ($W as $c => $w): ?><tr<?= (int)$w['active'] ? '' : ' class="muted"' ?>><td><a href="?view=worker&code=<?= h(urlencode($c)) ?>"><?= h($c) ?></a></td><td><?= h($w['name']) ?><?= $w['name_local'] !== '' ? ' <span class="muted">' . h($w['name_local']) . '</span>' : '' ?></td><td><?= h($bn[$w['branch_id']] ?? '') ?></td><td><?= h($w['dept']) ?></td><td><?= h($w['skill']) ?></td><td><?= (int)$w['active'] ? '' : 'Off' ?></td></tr><?php endforeach; ?>
<?php if (!$W): ?><tr><td colspan="6" class="muted">No workers yet.</td></tr><?php endif; ?></table></div>
<?php if ($R['entry']): $wf = function (array $e, string $code) use ($opts, $x, $bn): void { ?>
<div class="grid"><label>Full name * <input name="name" value="<?= h((string)($e['name'] ?? '')) ?>" required></label><label>Name in local script <input name="name_local" value="<?= h((string)($e['name_local'] ?? '')) ?>"></label>
<label>Father's / husband's name <input name="guardian" value="<?= h((string)($e['guardian'] ?? '')) ?>"></label><label>Gender <select name="gender"><?= $opts(array_combine(PRD_GENDERS, PRD_GENDERS), (string)($e['gender'] ?? ''), '—') ?></select></label>
<label>Mobile <input name="mobile" value="<?= h((string)($e['mobile'] ?? '')) ?>" inputmode="tel"></label><label>Branch * <select name="branch_id" required><?= $opts(array_intersect_key($bn, array_flip($x['branches'])), (string)($e['branch_id'] ?? '')) ?></select></label>
<label>Department / section <input name="dept" value="<?= h((string)($e['dept'] ?? '')) ?>"></label><label>Date of joining <input type="date" name="doj" value="<?= h((string)($e['doj'] ?? '')) ?>"></label>
<label>Card / token no. <input name="card_no" value="<?= h((string)($e['card_no'] ?? '')) ?>"></label><label>Skill <select name="skill"><?= $opts(array_combine(PRD_SKILLS, PRD_SKILLS), (string)($e['skill'] ?? ''), '—') ?></select></label>
<label>Paid by <select name="paid_by"><?= $opts(PRD_PAID_BY, (string)($e['paid_by'] ?? 'bank')) ?></select></label><label>Note <input name="note" value="<?= h((string)($e['note'] ?? '')) ?>"></label></div>
<label><input type="checkbox" name="same_ok" value="1"> A different person (same name as another worker here)</label><?php }; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="worker"><h3>Add a worker</h3><?php $wf($f, ''); ?><button class="btn sm">Add</button></form><?php endif; ?>

<?php elseif ($view === 'worker' && isset($W[(string)($_GET['code'] ?? '')])): $c = (string)$_GET['code']; $w = $W[$c]; $f = $_SESSION['prd_form'] ?? []; unset($_SESSION['prd_form']); $e = $f ?: $w; ?>
<div class="card"><h2><?= h($w['name']) ?> <span class="muted"><?= h($c) ?></span></h2><p><?= h(implode(' · ', array_filter([$bn[$w['branch_id']] ?? '', $w['dept'], $w['skill'], $w['doj'] ? 'joined ' . $w['doj'] : '', (int)$w['active'] ? '' : 'switched off — ' . $w['off_reason']]))) ?></p></div>
<?php if ($R['entry']): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="worker"><input type="hidden" name="code" value="<?= h($c) ?>"><h3>Details</h3>
<div class="grid"><label>Full name * <input name="name" value="<?= h((string)$e['name']) ?>" required></label><label>Name in local script <input name="name_local" value="<?= h((string)$e['name_local']) ?>"></label>
<label>Father's / husband's name <input name="guardian" value="<?= h((string)$e['guardian']) ?>"></label><label>Gender <select name="gender"><?= $opts(array_combine(PRD_GENDERS, PRD_GENDERS), (string)$e['gender'], '—') ?></select></label>
<label>Mobile <input name="mobile" value="<?= h((string)$e['mobile']) ?>"></label><label>Branch <select name="branch_id"><?= $opts(array_intersect_key($bn, array_flip($x['branches'])), (string)$e['branch_id']) ?></select></label>
<label>Department <input name="dept" value="<?= h((string)$e['dept']) ?>"></label><label>Date of joining <input type="date" name="doj" value="<?= h((string)$e['doj']) ?>"></label>
<label>Card / token no. <input name="card_no" value="<?= h((string)$e['card_no']) ?>"></label><label>Skill <select name="skill"><?= $opts(array_combine(PRD_SKILLS, PRD_SKILLS), (string)$e['skill'], '—') ?></select></label>
<label>Paid by <select name="paid_by"><?= $opts(PRD_PAID_BY, (string)$e['paid_by']) ?></select></label><label>Note <input name="note" value="<?= h((string)$e['note']) ?>"></label></div>
<label><input type="checkbox" name="active" value="1"<?= (int)($e['active'] ?? 1) ? ' checked' : '' ?>> On the register</label> <input name="off_reason" placeholder="Why switched off (when unticking)"> <label><input type="checkbox" name="same_ok" value="1"> A different person</label><br><button class="btn sm">Save</button></form><?php endif; ?>
<?php if ($R['money']): $hist = prd_rate_hist($db)[$c] ?? []; ?><div class="card"><h3>Hourly rate</h3><table><tr><th>From</th><th>Per hour</th></tr><?php foreach (array_reverse($hist, true) as $from => $m): ?><tr><td><?= h($from) ?></td><td><?= h($cur . ' ' . prd_money($m)) ?></td></tr><?php endforeach; ?><?php if (!$hist): ?><tr><td colspan="2" class="muted">No rate yet — hours cannot be paid.</td></tr><?php endif; ?></table>
<form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="rate"><input type="hidden" name="code" value="<?= h($c) ?>"><input type="date" name="from" required><input name="amount" placeholder="Per hour" required size="8"><input name="note" placeholder="Note"><button class="btn sm">Add rate</button></form>
<p class="muted">Each day is paid at the rate in force that day. A rate inside a confirmed month needs the month reopened.</p></div><?php endif; ?>

<?php elseif ($view === 'pay'): $rows = prd_month_rows($db, $x, $ym); $I = $R['money'] ? prd_inputs($db, $ym) : []; $dl = $set['ded_labels']; echo $monthBar('pay'); ?>
<?php if ($R['money']): $tot = array_sum(array_map(fn($r) => $r['pay']['net'], $rows)); $flags = array_sum(array_map(fn($r) => count(array_filter($r['pay']['flags'], fn($f) => $f[0] === 'bad')), $rows)); ?>
<div class="card row" style="flex-wrap:wrap"><span><b><?= count($rows) ?></b> workers</span> · <span>Gross <b><?= h(prd_money(array_sum(array_map(fn($r) => $r['pay']['gross'], $rows)))) ?></b></span> · <span>Net <b><?= h(prd_money($tot)) ?></b> <?= h($cur) ?></span> · <span><?= $flags ? '<b>' . $flags . ' to fix</b>' : 'Nothing to fix' ?></span>
 · <a class="btn sm ghost" href="?view=csv&ym=<?= h($ym) ?>">Download CSV</a></div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="inputs"><input type="hidden" name="ym" value="<?= h($ym) ?>">
<table style="font-size:.85em"><tr><th>Worker</th><th>Days</th><th>Hours / OT</th><th>Pieces</th><th>Wages + OT + pieces</th><th>Other earnings</th><th>Advance recovery<br><span class="muted">outstanding</span></th><th><?= h($dl[0]) ?></th><th><?= h($dl[1]) ?></th><th><?= h($dl[2]) ?></th><th>Other ded.</th><th>Net</th></tr>
<?php foreach ($rows as $c => $r): $p = $r['pay']; $in = fn($k) => $locked ? h(prd_money($p['in'][$k])) : '<input name="in[' . h($c) . '][' . $k . ']" value="' . ($p['in'][$k] ? h(prd_money($p['in'][$k])) : '') . '" size="6" style="width:5.5em">'; ?>
<tr><td><?= h($r['name']) ?><div class="muted"><?= h($c) ?></div><?php foreach ($p['flags'] as [$k, $m]): ?><div style="color:<?= $k === 'bad' ? 'var(--bad,#b00)' : '#a60' ?>"><?= h($m) ?></div><?php endforeach; ?></td><td><?= $p['days'] ?></td><td><?= h(prd_hrs($p['worked'])) ?> / <?= h(prd_hrs($p['ot'])) ?></td><td><?= $p['pcs'] ?></td>
<td><?= h(prd_money($p['reg_pay'] + $p['ot_pay'] + $p['pc_pay'])) ?></td><td><?= $in('other_earn') ?></td><td><?= $in('adv_recover') ?><div class="muted"><?= h(prd_money($p['adv_out'])) ?></div></td><td><?= $in('ded_a') ?></td><td><?= $in('ded_b') ?></td><td><?= $in('ded_c') ?></td><td><?= $in('other_ded') ?></td><td><b><?= h(prd_money($p['net'])) ?></b></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="12" class="muted">No workers this month.</td></tr><?php endif; ?></table><?php if (!$locked && $rows): ?><button class="btn sm">Save inputs</button><?php endif; ?></form>
<?php if (!$locked && $x['scope'] === null): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="confirm"><input type="hidden" name="ym" value="<?= h($ym) ?>"><button class="btn">Confirm and lock <?= h(date('F Y', strtotime("$ym-01"))) ?></button> <span class="muted">Only once the month has ended and nothing is left to fix. Advance recoveries go into the ledger.</span></form>
<?php elseif ($locked): ?><form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="reopen"><input type="hidden" name="ym" value="<?= h($ym) ?>"><input name="why" placeholder="Why reopen" required><button class="btn sm ghost">Reopen</button></form><?php endif; ?>
<?php else: ?><div class="card"><table><tr><th>Worker</th><th>Days</th><th>Hours</th><th>OT</th><th>Pieces</th></tr><?php foreach ($rows as $c => $r): $p = $r['pay']; ?><tr><td><?= h($r['name']) ?></td><td><?= $p['days'] ?></td><td><?= h(prd_hrs($p['worked'])) ?></td><td><?= h(prd_hrs($p['ot'])) ?></td><td><?= $p['pcs'] ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<?php elseif ($view === 'adv' && $R['money']): $L = prd_adv_lines($db, '9999-12-31'); $sum = prd_adv_sum($L); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="adv"><h3>Advance line</h3><div class="grid"><label>Worker <select name="code" required><?= $opts($names, '', '—') ?></select></label><label>What <select name="kind"><?= $opts(PRD_ADV_KINDS, 'given') ?></select></label>
<label>Date <input type="date" name="d" value="<?= $today ?>" max="<?= $today ?>"></label><label>Amount <input name="amount" required></label><label>Payment reference (needed when given) <input name="ref"></label><label>Note <input name="note"></label></div><button class="btn sm">Add</button>
<p class="muted">Nothing is deducted from pay without a recovery typed on the pay run.</p></form>
<div class="card"><h3>Outstanding</h3><table><?php foreach ($sum as $c => $m): if (!$m) continue; ?><tr><td><?= h(($W[$c]['name'] ?? $c) . ' · ' . $c) ?></td><td><?= h(prd_money($m)) ?></td></tr><?php endforeach; ?></table>
<h3>Ledger</h3><table><tr><th>Date</th><th>Worker</th><th>What</th><th>Amount</th><th>Reference</th><th></th></tr><?php foreach (array_reverse($L) as $l): if (!isset($W[$l['code']])) continue; ?><tr><td><?= h($l['d']) ?></td><td><?= h($l['code']) ?></td><td><?= h(PRD_ADV_KINDS[$l['kind']]) ?></td><td><?= h(prd_money((int)$l['minor'])) ?></td><td><?= h($l['ref']) ?></td>
<td><?php if ($l['pay_ym'] === null): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="advoff"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input name="why" placeholder="Why" size="8" required><button class="btn sm ghost">Take off</button></form><?php else: ?>Pay run<?php endif; ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($view === 'rates' && $R['money']): $T = prd_piece_types($db); ?>
<div class="card"><h3>Piece-rate types</h3><table><tr><th>Type</th><th>Rates (from → per piece)</th><th></th></tr>
<?php foreach ($T as $id => $t): ?><tr<?= (int)$t['active'] ? '' : ' class="muted"' ?>><td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="type"><input type="hidden" name="id" value="<?= $id ?>"><input name="name" value="<?= h($t['name']) ?>" size="12"><label><input type="checkbox" name="active" value="1"<?= (int)$t['active'] ? ' checked' : '' ?>> on</label><button class="btn sm ghost">Save</button></form></td>
<td><?= h(implode(' · ', array_map(fn($f, $m) => $f . ' → ' . prd_money($m), array_keys($t['hist']), $t['hist'])) ?: 'No rate yet') ?></td>
<td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="prate"><input type="hidden" name="id" value="<?= $id ?>"><input type="date" name="from" required><input name="amount" placeholder="Per piece" size="6" required><button class="btn sm">Add rate</button></form></td></tr><?php endforeach; ?></table>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="type"><input type="hidden" name="active" value="1"><input name="name" placeholder="New type of work" required><button class="btn sm">Add type</button></form>
<p class="muted">Pieces are paid at the type's rate in force on the day they were made.</p></div>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['entry_roles' => 'Enter hours, pieces and workers (their ticked branches; none ticked = nothing) — never see money', 'money_roles' => 'Rates, advances and the pay run', 'view_roles' => 'Read hours and totals'] as $k => $l): ?><h3><?= h($l) ?></h3>
<div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $code => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<div class="grid"><label>Standard day (hours above it are overtime) <input name="std_hours" value="<?= h(prd_hrs((int)$set['std_centi'])) ?>"></label><label>Overtime multiplier (1 = same as the hourly rate) <input name="ot_multiplier" value="<?= h(rtrim(rtrim(number_format($set['ot_permille'] / 1000, 3, '.', ''), '0'), '.')) ?>"></label>
<label>First month of production pay (blank = any) <input type="month" name="first_month" value="<?= h($set['first_month']) ?>"></label><label>Worker code starts with <input name="prefix" value="<?= h($set['prefix']) ?>" maxlength="4"></label>
<?php foreach ([0, 1, 2] as $i): ?><label>Deduction <?= $i + 1 ?> label <input name="ded_labels[<?= $i ?>]" value="<?= h($set['ded_labels'][$i]) ?>"></label><?php endforeach; ?></div><button class="btn">Save</button></form>

<?php elseif ($view === 'log' && $isAdmin): $L = $db->query('SELECT l.*, u.name AS who FROM prd_log l LEFT JOIN users u ON u.id = l.by_user ORDER BY l.id DESC LIMIT 300')->fetchAll(); ?>
<div class="card"><table><tr><th>When</th><th>Who</th><th>What</th><th>Worker</th><th>Ref</th><th>Detail</th></tr><?php foreach ($L as $l): ?><tr><td><?= h(substr($l['at'], 0, 16)) ?></td><td><?= h((string)$l['who']) ?></td><td><?= h($l['what']) ?></td><td><?= h($l['code']) ?></td><td><?= h($l['ref']) ?></td><td><?= h($l['detail']) ?></td></tr><?php endforeach; ?></table></div>
<?php else: ?><div class="card">Not found.</div><?php endif;
ui_foot();
