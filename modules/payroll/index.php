<?php
// Payroll — month run (prepare → check → confirm → release), people & CTC, rules by month, log.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/payroll.php';
require_once WN_ROOT . '/lib/fin_note.php';
$u = module_require('payroll');
$db = pdo();
if (!pay_can($u)) { http_response_code(403); ui_head('Payroll', $u, 'payroll'); echo '<section class="card"><p>Salary figures are open only to logins the Super Admin allows (pay data).</p></section>'; ui_foot(); exit; }
$view = (string)($_GET['view'] ?? 'month');
$ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['ym'] ?? $_POST['ym'] ?? '')) ? (string)($_GET['ym'] ?? $_POST['ym']) : date('Y-m', strtotime('first day of last month'));
$canRules = is_superadmin($u) || has_role($u, 'CEO');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $to = 'index.php?view=' . urlencode((string)($_POST['view'] ?? $view)) . '&ym=' . $ym;
    try {
        switch ($a) {
            case 'input': pay_input_save($db, $u, $ym, (string)$_POST['code'], $_POST); flash('Saved.'); break;
            case 'loan': pay_loan_add($db, $u, $_POST); flash('Saved. Recovery starts in ' . date('F Y', strtotime($_POST['from_ym'] . '-01')) . '.'); $to = 'index.php?view=loans&ym=' . $ym; break;
            case 'arrear': $amt = pay_arrear_add($db, $u, $ym, (string)$_POST['from_ym'], (string)$_POST['code'], (string)($_POST['note'] ?? '')); flash('Arrears of ' . pay_money($amt) . ' added to this month.'); break;
            case 'arrearoff': pay_arrear_off($db, $u, (int)$_POST['id'], (string)($_POST['reason'] ?? '')); flash('Taken off.'); break;
            case 'loanclose': pay_loan_close($db, $u, (int)$_POST['id'], (string)($_POST['reason'] ?? '')); flash('Closed.'); $to = 'index.php?view=loans&ym=' . $ym; break;
            case 'check': case 'confirm': case 'release': case 'reopen': pay_step($db, $u, $ym, $a, (string)($_POST['note'] ?? '')); flash(PAY_STATUS[pay_month_row($db, $ym)['status']] . '.'); break;
            case 'profile': pay_profile_save($db, $u, (string)$_POST['code'], $_POST); flash('Saved.'); $to = 'index.php?view=people&code=' . urlencode((string)$_POST['code']); break;
            case 'payoff':
                if (trim((string)($_POST['reason'] ?? '')) === '') throw new InvalidArgumentException('Give a reason.');
                $db->prepare('INSERT INTO pay_emp (emp_code, active, off_reason, updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE active = VALUES(active), off_reason = VALUES(off_reason), updated_by = VALUES(updated_by)')
                   ->execute([strtoupper((string)$_POST['code']), !empty($_POST['on']) ? 1 : 0, !empty($_POST['on']) ? '' : mb_substr(trim((string)$_POST['reason']), 0, 300), (int)$u['id']]);
                audit((int)$u['id'], 'pay_' . (!empty($_POST['on']) ? 'on' : 'off'), strtoupper((string)$_POST['code'])); flash('Saved.'); $to = 'index.php?view=people'; break;
            case 'rules':
                $R = pay_rules($db, (string)$_POST['from_ym']);
                $R['ctc_includes_employer'] = !empty($_POST['ctc_inc']);
                $stc = $R['structure'];
                $R['structure'] = ['basic_pct' => max(0, min(100, isset($_POST['basic_pct']) ? (float)$_POST['basic_pct'] : (float)$stc['basic_pct'])), 'basic_min' => max(0, isset($_POST['basic_min']) ? (float)$_POST['basic_min'] : (float)$stc['basic_min']),
                    'hra_pct' => max(0, min(100, isset($_POST['hra_pct']) ? (float)$_POST['hra_pct'] : (float)$stc['hra_pct'])), 'fixed' => isset($_POST['fixed']) ? pay_fixed_parse((string)$_POST['fixed']) : $stc['fixed']];
                $num = fn(string $k, $cur) => isset($_POST[$k]) && is_numeric($_POST[$k]) ? (float)$_POST[$k] : (float)$cur;   // a missing field keeps today's value
                if (isset($R['pf'])) { $pf = $R['pf']; $R['pf'] = ['on' => !empty($_POST['pf_on']), 'emp_pct' => $num('pf_emp', $pf['emp_pct']), 'er_pct' => $num('pf_er', $pf['er_pct']), 'eps_pct' => $num('pf_eps', $pf['eps_pct']), 'ceiling' => $num('pf_ceiling', $pf['ceiling']),
                    'parts' => isset($_POST['pf_parts']) ? (array_values(array_filter(array_map('trim', explode(',', (string)$_POST['pf_parts'])))) ?: ['Basic']) : $pf['parts'], 'charges' => !empty($_POST['pf_charges']), 'edli_pct' => $num('pf_edli', $pf['edli_pct']), 'admin_pct' => $num('pf_admin', $pf['admin_pct'])]; }
                if (isset($R['esic'])) { $es = $R['esic']; $R['esic'] = ['on' => !empty($_POST['esic_on']), 'emp_pct' => $num('esic_emp', $es['emp_pct']), 'er_pct' => $num('esic_er', $es['er_pct']), 'ceiling' => $num('esic_ceiling', $es['ceiling'])]; }
                if (isset($R['lwf']) && isset($_POST['lwf']) && function_exists('rp_in_lwf_parse')) { $lw = []; foreach ((array)$_POST['lwf'] as $st => $txt) if (($v = rp_in_lwf_parse((string)$txt)) !== null) $lw[(string)$st] = $v; $R['lwf'] = $lw; $R['lwf_deduct'] = !empty($_POST['lwf_deduct']); }
                if (isset($R['pt'], $_POST['pt'])) { $pt = []; foreach ((array)($_POST['pt'] ?? []) as $st => $txt) if (trim((string)$txt) !== '') $pt[(string)$st] = pay_pt_parse((string)$txt); $R['pt'] = $pt; }
                if (isset($_POST['paid_leave'])) $R['paid_leave'] = array_values(array_intersect(array_column(lv_types($db, false), 'code'), (array)($_POST['paid_leave'] ?? [])));
                if (isset($_POST['confirm_roles'])) $R['confirm_roles'] = array_values(array_intersect(array_keys(role_labels()), (array)($_POST['confirm_roles'] ?? []))) ?: ['CEO'];
                pay_rule_save($db, $u, (string)$_POST['from_ym'], $R, (string)($_POST['reason'] ?? ''));
                flash('Rules saved from ' . date('F Y', strtotime($_POST['from_ym'] . '-01')) . '.'); $to = 'index.php?view=rules&ym=' . $_POST['from_ym']; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

$m = pay_month_row($db, $ym);
$M = fn($v) => h(pay_money((float)$v));
if ($view === 'bank') {
    if (!bank_can($u)) { http_response_code(403); exit('The bank file needs pay and bank data.'); }
    if ($m['status'] !== 'released') { http_response_code(409); exit('Release the month first.'); }
    $rows = pay_build($db, $ym, $u);
    $b = bank_rows($rows, bank_details($db, array_column($rows, 'emp_code')), $ym, org('name'));
    $fmt = isset(BANK_FORMATS[$_GET['fmt'] ?? '']) ? (string)$_GET['fmt'] : 'generic';
    audit((int)$u['id'], 'pay_bank_file', $ym . ' ' . $fmt . ' rows ' . count($b['rows']) . ' left out ' . count($b['left_out']));
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="salary-' . $ym . '-' . $fmt . '.csv"'); header('Cache-Control: no-store');
    echo $fmt === 'neft' ? bank_csv_line(['Account', 'IFSC', 'Name', 'Amount', 'Narration']) : bank_csv_line(['Name', 'Account', 'Bank code', 'Amount', 'Reference']);
    foreach ($b['rows'] as $r) echo $fmt === 'neft' ? bank_csv_line([$r['acct'], $r['code'], $r['name'], number_format($r['amount'], 2, '.', ''), $r['ref']]) : bank_csv_line([$r['name'], $r['acct'], $r['code'], number_format($r['amount'], 2, '.', ''), $r['ref']]);
    exit;
}
if ($view === 'return') {
    try { $f = pay_return($db, $u, $ym, (string)($_GET['kind'] ?? '')); }
    catch (Throwable $e) { flash($e->getMessage(), 'err'); redirect('index.php?view=month&ym=' . $ym); }
    header('Content-Type: ' . $f['mime'] . '; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $f['name'] . '"'); header('Cache-Control: no-store');
    echo $f['body']; exit;
}
if ($view === 'csv') {
    $rows = pay_build($db, $ym, $u);
    audit((int)$u['id'], 'pay_csv', $ym . ' rows ' . count($rows));
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="payroll-' . $ym . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    $cell = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    $heads = []; foreach ($rows as $r) foreach (['earned', 'deductions', 'employer'] as $g) foreach (array_keys($r[$g] ?? []) as $k) $heads[$g][$k] = true;
    fputcsv($out, array_merge(['Code', 'Name', 'Paid days', 'Days'], array_keys($heads['earned'] ?? []), ['Other earnings', 'Gross'], array_keys($heads['deductions'] ?? []), ['Net'], array_map(fn($k) => 'Employer ' . $k, array_keys($heads['employer'] ?? [])), ['Cost', 'Hold', 'Flags']));
    foreach ($rows as $r) fputcsv($out, array_map($cell, array_merge([$r['emp_code'], $r['name'], $r['paid_days'] ?? '', $r['days'] ?? ''],
        array_map(fn($k) => $r['earned'][$k] ?? 0, array_keys($heads['earned'] ?? [])), [$r['other_earn'] ?? 0, $r['gross']], array_map(fn($k) => $r['deductions'][$k] ?? 0, array_keys($heads['deductions'] ?? [])),
        [$r['net']], array_map(fn($k) => $r['employer'][$k] ?? 0, array_keys($heads['employer'] ?? [])), [$r['cost'], $r['hold'] ? 'Hold: ' . $r['hold_reason'] : '', implode('; ', $r['flags'])])));
    exit;
}
ui_head('Payroll', $u, 'payroll');
?>
<nav class="row"><?php foreach (['month' => 'Month', 'people' => 'People & CTC', 'loans' => 'Loans & advances', 'rules' => 'Rules', 'log' => 'Log'] as $k => $l): ?><a class="btn sm<?= $view === $k ? '' : ' ghost' ?>" href="?view=<?= $k ?>&ym=<?= $ym ?>"><?= $l ?></a><?php endforeach; ?>
  <form class="row"><input type="hidden" name="view" value="<?= h($view) ?>"><input type="month" name="ym" value="<?= h($ym) ?>" onchange="this.form.submit()" aria-label="Month"></form></nav>

<?php if ($view === 'month'):
    $rows = pay_build($db, $ym, $u); $t = pay_totals($rows); $draft = $m['status'] === 'draft'; $open = (string)($_GET['code'] ?? ''); ?>
<section class="card"><h2><?= h(date('F Y', strtotime($ym . '-01'))) ?> <span class="pill <?= $draft ? 'port' : 'on' ?>"><?= h(PAY_STATUS[$m['status']]) ?></span><?= $draft || $m['status'] === 'checked' ? '' : ' ' . fin_feed_note($db, $u, 'payroll', $ym) ?></h2>
  <p><span class="pill">People <?= $t['people'] ?></span> <span class="pill">Gross <?= $M($t['gross']) ?></span> <span class="pill">Deductions <?= $M($t['deductions']) ?></span> <span class="pill">Net <?= $M($t['net']) ?></span>
     <span class="pill">Employer <?= $M($t['employer']) ?></span> <span class="pill">Cost <?= $M($t['cost']) ?></span><?= $t['held'] ? ' <span class="pill port">On hold ' . $t['held'] . '</span>' : '' ?><?= $t['flags'] ? ' <span class="pill port">To look at ' . $t['flags'] . '</span>' : '' ?></p>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="ym" value="<?= $ym ?>">
    <?php if ($draft): ?><button class="btn sm" name="a" value="check">Mark checked</button><?php endif; ?>
    <?php if ($m['status'] === 'checked'): ?><button class="btn sm" name="a" value="confirm">Confirm and lock</button><?php endif; ?>
    <?php if ($m['status'] === 'confirmed'): ?><button class="btn sm" name="a" value="release">Release</button><?php endif; ?>
    <?php if (!$draft): ?><input name="note" placeholder="Reason to reopen"><button class="btn sm ghost" name="a" value="reopen">Reopen</button><?php endif; ?>
    <a class="btn sm ghost" href="?view=csv&ym=<?= $ym ?>">Download CSV</a></form>
  <?php if ($m['status'] === 'released' && bank_can($u)): $bk = bank_preview($db, $rows, $ym); ?>
  <p>Bank file: <?= count($bk['rows']) ?> payments, <?= $M($bk['total']) ?> <?php foreach (BANK_FORMATS as $k => $l): ?><a class="btn sm ghost" href="?view=bank&ym=<?= $ym ?>&fmt=<?= $k ?>"><?= h($l) ?></a> <?php endforeach; ?></p>
  <?php if ($bk['left_out']): ?><p class="muted">Left out: <?= h(implode(' · ', array_map(fn($x) => $x[1] . ' (' . $x[2] . ')', $bk['left_out']))) ?></p><?php endif; endif; ?>
  <?php if (pay_locked($m)): ?><p><b>Returns</b> <?php foreach (pay_return_kinds(pay_pack()) as $k => $l): $pv = pay_return_preview($db, $rows, $k); ?><a class="btn sm ghost" href="?view=return&ym=<?= $ym ?>&kind=<?= h($k) ?>"><?= h($l) ?></a><?= $pv['left_out'] ? ' <small class="muted">' . count($pv['left_out']) . ' without a number: ' . h(implode(', ', array_map(fn($x) => $x[1], $pv['left_out']))) . '</small>' : '' ?> <?php endforeach; ?></p><?php endif; ?>
  <p class="muted">Checked by someone other than the person who entered the inputs; confirmed and released by <?= h(implode(' / ', array_map(fn($r) => role_labels()[$r] ?? $r, (array)pay_rules($db, $ym)['confirm_roles']))) ?>. A confirmed month is read from its snapshot.</p></section>
<?php if ($draft): $arr = pay_arrears($db, $u, $ym); $have = pay_arrear_lines($db, $ym); if ($arr || $have): ?>
<section class="card"><h2>Arrears</h2><p class="muted">Locked months worked out again from today's attendance, leave and rules. Earnings that differ can be paid or recovered in this month, once per person; statutory is not worked out on them.</p>
  <table><tbody><?php foreach ($arr as $a): ?><tr><td><?= h($a['name'] . ' · ' . $a['emp_code']) ?></td><td><?= h(date('M Y', strtotime($a['from_ym'] . '-01'))) ?></td><td><?= h(implode(' · ', array_map(fn($k, $v) => $k . ' ' . pay_money($v), array_keys($a['parts']), $a['parts']))) ?></td><td><b><?= $M($a['total']) ?></b></td>
    <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="arrear"><input type="hidden" name="ym" value="<?= $ym ?>"><input type="hidden" name="from_ym" value="<?= h($a['from_ym']) ?>"><input type="hidden" name="code" value="<?= h($a['emp_code']) ?>"><input name="note" placeholder="Note"><button class="btn sm"><?= $a['total'] > 0 ? 'Pay' : 'Recover' ?> in this month</button></form></td></tr><?php endforeach; ?>
  <?php foreach ($have as $a): ?><tr><td><?= h(($a['name'] ?? '') . ' · ' . $a['emp_code']) ?></td><td><?= h(date('M Y', strtotime($a['from_ym'] . '-01'))) ?></td><td><?= $a['active'] ? '<span class="pill on">In this month</span>' : '<span class="pill off">Taken off</span> ' . h($a['off_reason']) ?></td><td><b><?= $M($a['amount']) ?></b></td>
    <td><?php if ($a['active']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="arrearoff"><input type="hidden" name="ym" value="<?= $ym ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input name="reason" placeholder="Reason" required><button class="btn sm ghost">Take off</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php endif; endif; ?>
<section class="card"><table><thead><tr><th>Name</th><th>Paid days</th><th>Gross</th><th>Deductions</th><th>Net</th><th>To look at</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td><?= h($r['name'] . ' · ' . $r['emp_code']) ?><?= $r['hold'] ? ' <span class="pill port">Hold</span>' : '' ?></td><td><?= h((string)($r['paid_days'] ?? '')) ?> / <?= (int)($r['days'] ?? 0) ?></td>
    <td><?= $M($r['gross']) ?></td><td><?= $M(array_sum($r['deductions'] ?? [])) ?></td><td><b><?= $M($r['net']) ?></b></td><td><small><?= h(implode(' · ', $r['flags'])) ?></small></td>
    <td><a href="?view=month&ym=<?= $ym ?>&code=<?= urlencode($r['emp_code']) ?>#l">Open</a></td></tr>
  <?php if (strcasecmp($open, $r['emp_code']) === 0 && empty($r['missing_ctc'])): ?><tr id="l"><td colspan="7">
    <div class="grid2"><div><b>Earned</b><table><tbody><?php foreach ($r['earned'] as $k => $v): ?><tr><td><?= h($k) ?></td><td><?= $M($r['parts'][$k] ?? 0) ?> / month</td><td><?= $M($v) ?></td></tr><?php endforeach; ?>
      <?php if ($r['other_earn']): ?><tr><td>Other earnings</td><td></td><td><?= $M($r['other_earn']) ?></td></tr><?php endif; ?>
      <?php if (($r['arrear'] ?? 0) > 0): ?><tr><td>Arrears</td><td></td><td><?= $M($r['arrear']) ?></td></tr><?php endif; ?></tbody></table></div>
    <div><b>Deductions</b><table><tbody><?php foreach ($r['deductions'] as $k => $v): ?><tr><td><?= h($k) ?></td><td><?= $M($v) ?></td></tr><?php endforeach; ?></tbody></table>
      <b>Employer</b><table><tbody><?php foreach ($r['employer'] as $k => $v): ?><tr><td><?= h($k) ?></td><td><?= $M($v) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php if (!$draft && $m['status'] !== 'checked' && module_on('payslips')): ?><p><a href="../payslips/?code=<?= urlencode($r['emp_code']) ?>&ym=<?= $ym ?>">Payslip preview</a></p><?php endif; ?>
    <?php if ($draft && strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) !== 0): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="input"><input type="hidden" name="ym" value="<?= $ym ?>"><input type="hidden" name="code" value="<?= h($r['emp_code']) ?>">
      <label>Other earnings <input type="number" step="0.01" min="0" name="other_earn" value="<?= h((string)$r['other_earn']) ?>"></label><label>TDS <input type="number" step="0.01" min="0" name="tds" value="<?= h((string)($r['deductions']['Income tax (TDS)'] ?? 0)) ?>"></label>
      <label>Other deductions <input type="number" step="0.01" min="0" name="other_ded" value="<?= h((string)($r['deductions']['Other deductions'] ?? 0)) ?>"></label><label>Note <input name="note" value="<?= h($r['note']) ?>"></label>
      <label class="tick"><input type="checkbox" name="hold" value="1"<?= $r['hold'] ? ' checked' : '' ?>> Hold</label><input name="hold_reason" placeholder="Reason to hold" value="<?= h($r['hold_reason']) ?>"><button class="btn sm">Save</button></form><?php endif; ?>
  </td></tr><?php endif; ?>
<?php endforeach; ?><?= $rows ? '' : '<tr><td colspan="7" class="muted">Nobody with a joining date in this month.</td></tr>' ?></tbody></table></section>

<?php elseif ($view === 'people'):
    $code = strtoupper((string)($_GET['code'] ?? '')); $ctc = pay_ctc_rows($db); $now = date('Y-m'); ?>
<?php if ($code !== '' && ($e = emp_by_code($db, $code)) && emp_in_scope($u, $e)): $p = pay_profile($db, $code); ?>
<p><a href="?view=people">‹ Everyone</a></p>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="profile"><input type="hidden" name="code" value="<?= h($code) ?>"><h2><?= h($e['name'] . ' · ' . $code) ?></h2>
  <div class="grid"><label>New monthly CTC <input type="number" step="0.01" min="0" name="ctc"></label><label>From month <input type="month" name="from_ym" value="<?= $now ?>"></label><label>Note <input name="note" maxlength="300"></label>
  <label>Paid by <select name="pay_mode"><?php foreach (PAY_MODES as $k => $l): ?><option value="<?= $k ?>"<?= $p['pay_mode'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>State insurance <select name="esic_on"><option value=""<?= $p['esic_on'] === null ? ' selected' : '' ?>>By the ceiling</option><option value="1"<?= $p['esic_on'] === 1 || $p['esic_on'] === '1' ? ' selected' : '' ?>>Yes</option><option value="0"<?= $p['esic_on'] === 0 || $p['esic_on'] === '0' ? ' selected' : '' ?>>No</option></select></label></div>
  <p><label class="tick"><input type="checkbox" name="pf_on" value="1"<?= $p['pf_on'] ? ' checked' : '' ?>> Provident fund member</label> <label class="tick"><input type="checkbox" name="pf_full" value="1"<?= $p['pf_full'] ? ' checked' : '' ?>> PF on full wages</label>
  <label class="tick"><input type="checkbox" name="pt_exempt" value="1"<?= $p['pt_exempt'] ? ' checked' : '' ?>> Professional tax exempt</label></p><button class="btn sm">Save</button></form>
<section class="card"><h2>CTC history</h2><table><tbody><?php foreach (array_reverse($ctc[$code] ?? []) as $r): ?><tr><td>From <?= h(date('M Y', strtotime($r['from_ym'] . '-01'))) ?></td><td><?= $M($r['ctc']) ?> / month</td><td><?= h($r['note']) ?></td><td><?= h(substr($r['at'], 0, 10)) ?></td></tr><?php endforeach; ?></tbody></table></section>
<form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="payoff"><input type="hidden" name="code" value="<?= h($code) ?>"><input type="hidden" name="on" value="<?= $p['active'] ? '' : '1' ?>"><input name="reason" placeholder="Reason" required>
  <button class="btn sm ghost"><?= $p['active'] ? 'Take off payroll' : 'Put back on payroll' ?></button><?= $p['active'] ? '' : ' <span class="pill off">Off payroll</span> ' . h($p['off_reason']) ?></form>
<?php else: ?>
<section class="card"><h2>People &amp; CTC</h2><table><thead><tr><th>Name</th><th>CTC now</th><th>PF</th><th>Paid by</th><th></th></tr></thead><tbody>
<?php foreach (emp_on_roll($db, $u) as $e): $p = pay_profile($db, $e['code']); $c = pay_ctc_for($ctc[strtoupper($e['code'])] ?? [], $now); ?>
  <tr><td><?= h($e['name'] . ' · ' . $e['code']) ?><?= $p['active'] ? '' : ' <span class="pill off">Off payroll</span>' ?></td><td><?= $c === null ? '<span class="pill port">Not entered</span>' : $M($c) ?></td><td><?= $p['pf_on'] ? 'Yes' : 'No' ?></td><td><?= h(PAY_MODES[$p['pay_mode']] ?? '') ?></td><td><a href="?view=people&code=<?= urlencode($e['code']) ?>">Open</a></td></tr>
<?php endforeach; ?></tbody></table></section>
<?php endif; ?>

<?php elseif ($view === 'rules'):
    $R = pay_rules($db, $ym); $pack = pay_pack(); $states = array_values(array_unique(array_filter(array_map(fn($b) => (string)$b['state'], branches(false))))); ?>
<section class="card"><h2>Rules in force for <?= h(date('F Y', strtotime($ym . '-01'))) ?></h2><p class="muted">Country rule pack: <b><?= h($pack['label']) ?></b> (Admin → Organisation → country). Figures are yours to confirm; each version applies from its month.</p>
  <table><tbody><?php foreach (array_reverse(pay_rule_versions($db)) as $v): ?><tr><td>From <?= h(date('M Y', strtotime($v['from_ym'] . '-01'))) ?></td><td><?= h($v['reason']) ?></td><td><?= h(substr($v['at'], 0, 10)) ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php if ($canRules): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="rules"><input type="hidden" name="view" value="rules"><h2>Change the rules</h2>
  <div class="grid"><label>Apply from <input type="month" name="from_ym" value="<?= h($ym) ?>" required></label><label>Reason <input name="reason" required maxlength="300"></label></div>
  <h3>Structure</h3><p><label class="tick"><input type="checkbox" name="ctc_inc" value="1"<?= $R['ctc_includes_employer'] ? ' checked' : '' ?>> CTC includes employer contributions (gross is worked back)</label></p>
  <div class="grid"><label>Basic % of gross <input type="number" step="0.01" name="basic_pct" value="<?= h((string)$R['structure']['basic_pct']) ?>"></label><label>Basic at least <input type="number" step="0.01" name="basic_min" value="<?= h((string)$R['structure']['basic_min']) ?>"></label>
  <label>HRA % of Basic <input type="number" step="0.01" name="hra_pct" value="<?= h((string)$R['structure']['hra_pct']) ?>"></label></div>
  <label>Fixed allowances (Name: amount, one per line) <textarea name="fixed" rows="3"><?= h(implode("\n", array_map(fn($f) => $f[0] . ': ' . $f[1], $R['structure']['fixed']))) ?></textarea></label>
  <?php if (isset($R['pf'])): $pf = $R['pf']; ?><h3>Provident fund</h3><p><label class="tick"><input type="checkbox" name="pf_on" value="1"<?= $pf['on'] ? ' checked' : '' ?>> On</label> <label class="tick"><input type="checkbox" name="pf_charges" value="1"<?= !empty($pf['charges']) ? ' checked' : '' ?>> Employer insurance and admin charges</label></p>
  <div class="grid"><label>Employee % <input name="pf_emp" value="<?= h((string)$pf['emp_pct']) ?>"></label><label>Employer % <input name="pf_er" value="<?= h((string)$pf['er_pct']) ?>"></label><label>Pension % (of employer) <input name="pf_eps" value="<?= h((string)$pf['eps_pct']) ?>"></label>
  <label>Wage ceiling <input name="pf_ceiling" value="<?= h((string)$pf['ceiling']) ?>"></label><label>On parts <input name="pf_parts" value="<?= h(implode(', ', (array)$pf['parts'])) ?>"></label><label>Insurance % <input name="pf_edli" value="<?= h((string)$pf['edli_pct']) ?>"></label><label>Admin % <input name="pf_admin" value="<?= h((string)$pf['admin_pct']) ?>"></label></div><?php endif; ?>
  <?php if (isset($R['esic'])): $es = $R['esic']; ?><h3>State insurance</h3><p><label class="tick"><input type="checkbox" name="esic_on" value="1"<?= $es['on'] ? ' checked' : '' ?>> On</label></p>
  <div class="grid"><label>Employee % <input name="esic_emp" value="<?= h((string)$es['emp_pct']) ?>"></label><label>Employer % <input name="esic_er" value="<?= h((string)$es['er_pct']) ?>"></label><label>Gross ceiling <input name="esic_ceiling" value="<?= h((string)$es['ceiling']) ?>"></label></div><?php endif; ?>
  <?php if (isset($R['pt'])): ?><h3>Professional tax by state</h3><p class="muted">One slab per line: from to amount (use - for "and above"), optionally a month number and that month's amount. States come from Admin → Organisation → branches.</p>
  <div class="grid"><?php foreach ($states as $st): ?><label><?= h($st) ?> <textarea name="pt[<?= h($st) ?>]" rows="4"><?= h(pay_pt_text($R['pt'][$st] ?? [])) ?></textarea></label><?php endforeach; ?><?= $states ? '' : '<p class="muted">No branch has a state yet.</p>' ?></div><?php endif; ?>
  <?php if (isset($R['lwf']) && function_exists('rp_in_lwf_parse')): ?><h3>Labour welfare fund by state</h3><p class="muted">Employee amount, employer amount, the months it is paid (e.g. "6 18 6,12"). Blank = none in that state.</p>
  <p><label class="tick"><input type="checkbox" name="lwf_deduct" value="1"<?= !empty($R['lwf_deduct']) ? ' checked' : '' ?>> Deduct the employee's share (else the employer pays both)</label></p>
  <div class="grid"><?php foreach ($states as $st): $lw = $R['lwf'][$st] ?? null; ?><label><?= h($st) ?> <input name="lwf[<?= h($st) ?>]" value="<?= $lw ? h($lw['emp'] . ' ' . $lw['er'] . ' ' . implode(',', $lw['months'])) : '' ?>"></label><?php endforeach; ?></div><?php endif; ?>
  <h3>Paid leave and approval</h3><p>Paid leave types: <?php foreach (lv_types($db, false) as $t): ?><label class="tick"><input type="checkbox" name="paid_leave[]" value="<?= h($t['code']) ?>"<?= ($R['paid_leave'] ? in_array($t['code'], $R['paid_leave'], true) : (bool)$t['paid']) ? ' checked' : '' ?>> <?= h($t['name']) ?></label> <?php endforeach; ?></p>
  <p>Confirms and releases: <?php foreach (['CEO', 'SUPERADMIN'] as $r): ?><label class="tick"><input type="checkbox" name="confirm_roles[]" value="<?= $r ?>"<?= in_array($r, (array)$R['confirm_roles'], true) ? ' checked' : '' ?>> <?= h(role_labels()[$r]) ?></label> <?php endforeach; ?></p>
  <button class="btn sm">Save rules from that month</button></form>
<?php endif; ?>

<?php elseif ($view === 'loans'): $ls = pay_loans($db); ?>
<section class="card"><h2>Loans &amp; advances</h2><table><thead><tr><th>Person</th><th>Kind</th><th>Amount</th><th>Monthly</th><th>From</th><th>Recovered</th><th>Left</th><th></th></tr></thead><tbody>
<?php foreach ($ls as $l): $rec = pay_loan_recovered($db, (int)$l['id'], '9999-12'); ?><tr><td><?= h(($l['name'] ?? '') . ' · ' . $l['emp_code']) ?></td><td><?= h(ucfirst($l['kind'])) ?></td><td><?= $M($l['amount']) ?></td><td><?= $M($l['emi']) ?></td><td><?= h(date('M Y', strtotime($l['from_ym'] . '-01'))) ?></td>
  <td><?= $M($rec) ?></td><td><?= $M(max(0, $l['amount'] - $rec)) ?></td><td><?php if (!$l['closed_at']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="loanclose"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input name="reason" placeholder="Reason" required><button class="btn sm ghost">Close</button></form><?php else: ?><span class="pill off">Closed</span> <?= h($l['close_reason']) ?><?php endif; ?></td></tr>
<?php endforeach; ?><?= $ls ? '' : '<tr><td colspan="8" class="muted">None.</td></tr>' ?></tbody></table><p class="muted">Recovered counts confirmed months only. Each draft month deducts the monthly amount, or what is left.</p></section>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="loan"><h2>New loan or advance</h2><div class="grid">
  <label>Person <select name="code" required><option value="">—</option><?php foreach (emp_on_roll($db, $u) as $e) if (strcasecmp($e['code'], (string)($u['emp_code'] ?? '')) !== 0): ?><option value="<?= h($e['code']) ?>"><?= h($e['name'] . ' · ' . $e['code']) ?></option><?php endif; ?></select></label>
  <label>Kind <select name="kind"><option value="loan">Loan</option><option value="advance">Salary advance</option></select></label><label>Amount <input type="number" step="0.01" min="1" name="amount" required></label>
  <label>Monthly recovery <input type="number" step="0.01" min="1" name="emi" required></label><label>First month <input type="month" name="from_ym" value="<?= date('Y-m') ?>" required></label><label>Note <input name="note" maxlength="300"></label></div><button class="btn sm">Save</button></form>

<?php elseif ($view === 'log'):
    $s = $db->prepare('SELECT l.*, u.name FROM pay_log l LEFT JOIN users u ON u.id = l.by_user WHERE l.ym = ? ORDER BY l.id DESC'); $s->execute([$ym]); ?>
<section class="card"><h2>Log · <?= h(date('F Y', strtotime($ym . '-01'))) ?></h2><table><tbody><?php foreach ($s as $l): ?><tr><td><?= h(date('j M H:i', strtotime($l['at']))) ?></td><td><?= h($l['text']) ?></td><td><?= h((string)$l['name']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php endif;
ui_foot();
