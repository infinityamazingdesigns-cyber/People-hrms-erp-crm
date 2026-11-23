<?php
// Statutory compliance — due dates, spends from locked payroll and their payments, official links, registrations, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/statutory.php';
$u = module_require('statutory');
$db = pdo();
st_seed($db);
$set = st_settings(); $R = st_rights($u, $set); $S = st_schemes(); $states = st_states();
$view = (string)($_GET['view'] ?? 'due');
$ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['ym'] ?? $_POST['ym'] ?? '')) ? (string)($_GET['ym'] ?? $_POST['ym']) : date('Y-m', strtotime('first day of last month'));
$today = date('Y-m-d');
$M = fn($v) => h(pay_money((float)$v));
$isAdmin = has_role($u, 'SUPERADMIN');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $to = 'index.php?view=' . urlencode($view) . '&ym=' . $ym;
    try {
        switch ($a) {
            case 'pull': st_need($u, 'manage'); [$n, $held] = st_pull($db, (int)$u['id'], $ym); flash($n . ' line(s) brought in from payroll' . ($held ? '; ' . $held . ' held salaries left out' : '') . '.'); break;
            case 'spendadd': st_spend_add($db, $u, $ym, $_POST); flash('Line added.'); break;
            case 'spendedit': st_spend_edit($db, $u, (int)$_POST['id'], $_POST); flash('Saved.'); break;
            case 'paid': st_spend_paid($db, $u, (int)$_POST['id'], $_POST); flash('Payment saved.'); break;
            case 'step': st_filing_add($db, $u, (int)$_POST['rule'], (string)$_POST['period'], (string)$_POST['step'], $_POST); flash(!empty($_POST['undo']) ? 'Taken back.' : 'Marked.'); break;
            case 'portal': st_portal_save($db, $u, (int)($_POST['id'] ?? 0), $_POST); flash('Saved.'); break;
            case 'due': st_due_save($db, $u, (int)($_POST['id'] ?? 0), $_POST); flash('Saved.'); break;
            case 'reg': st_reg_save($db, $u, (int)($_POST['id'] ?? 0), $_POST); flash('Saved.'); break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = st_settings_check($_POST, role_labels(), $S);
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['statutory'] ?? ['roles' => module_roles('statutory')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['statutory', 1, implode(',', $st['roles'] ?: module_roles('statutory')), json_encode($new), (int)$u['id']]);
                audit((int)$u['id'], 'module_settings', 'statutory');
                flash('Settings saved.'); break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}
if ($R['amounts']) st_pull_all($db);

$stateSel = function (string $name, string $cur = '') use ($states): string {
    $o = '<select name="' . h($name) . '"><option value="">Every state</option>';
    foreach ($states as $s) $o .= '<option' . ($s === $cur ? ' selected' : '') . '>' . h($s) . '</option>';
    return $o . '</select>';
};
$schemeSel = function (string $cur = '') use ($S): string {
    $o = '<select name="scheme" aria-label="Scheme">'; foreach ($S as $k => $l) $o .= '<option value="' . h($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . h($l) . '</option>';
    return $o . '</select>';
};
$pick = fn(array $opts, string $name, string $cur = '') => '<select name="' . h($name) . '">' . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($opts), $opts)) . '</select>';

ui_head('Statutory compliance', $u, 'statutory');
$tabs = ['due' => 'Due', 'links' => 'Links', 'regs' => 'Registrations'] + ($R['amounts'] ? ['spends' => 'Spends', 'year' => 'Year'] : []) + ($R['manage'] ? ['rules' => 'Due rules'] : []) + ($isAdmin ? ['settings' => 'Settings'] : []); ?>
<nav class="row"><?php foreach ($tabs as $k => $l): ?><a class="btn sm<?= $view === $k ? '' : ' ghost' ?>" href="?view=<?= $k ?>&ym=<?= $ym ?>"><?= $l ?></a><?php endforeach; ?>
<?php if (in_array($view, ['spends'], true)): ?><form class="row"><input type="hidden" name="view" value="<?= h($view) ?>"><input type="month" name="ym" value="<?= h($ym) ?>" onchange="this.form.submit()" aria-label="Month"></form><?php endif; ?></nav>
<?php if (!$S): ?><section class="card"><p class="muted">The country rules for this organisation have no statutory schemes. Pick the country on Admin → Organisation.</p></section><?php endif; ?>

<?php if ($view === 'due'): $open = st_open_dues($db, $today); $lockedM = st_pay_months($db); ?>
<section class="card"><h2>Due — overdue and the next 30 days</h2><table><thead><tr><th>Date</th><th>Due</th><th>For</th><th>Who</th><th>Where it stands</th><th></th></tr></thead><tbody>
<?php foreach ($open as $d): $r = $d['rule']; $s = $d['spend']; ?>
  <tr><td><?= $d['late'] ? '<span class="pill off">Late</span> ' : '' ?><?= h(date('j M Y', strtotime($d['date']))) ?></td><td><?= h($r['label']) ?><?= $r['state'] !== '' ? ' · ' . h($r['state']) : '' ?><?= (int)$r['checked'] ? '' : ' <small class="muted">(date to confirm)</small>' ?></td>
    <td><?= $d['ym'] !== '' ? h(date('M Y', strtotime($d['ym'] . '-01'))) : '' ?></td><td><?= h(ST_WHO[$r['who']] ?? '') ?></td>
    <td><?php if ($s && $R['amounts']): ?><?= h(st_status($s, $d['date'], $today)) ?> · <?= $M(st_total($s)) ?><?php elseif (in_array($r['kind'], ['pay', 'both'], true) && $d['ym'] !== '' && !$s): ?><span class="muted"><?= isset($lockedM[$d['ym']]) ? 'Nobody in it on payroll' : 'Payroll not confirmed yet' ?></span><?php endif; ?>
      <?php foreach ($d['steps'] as $k => $x): ?> <span class="pill on"><?= h(ucfirst($k)) ?></span><?php endforeach; ?></td>
    <td><?php foreach (st_rule_steps($r) as $step) if (!isset($d['steps'][$step]) && !($step === 'paid' && $s)): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="step"><input type="hidden" name="rule" value="<?= (int)$r['id'] ?>"><input type="hidden" name="period" value="<?= h($d['period']) ?>"><input type="hidden" name="step" value="<?= $step ?>">
      <?php if ($step !== 'done'): ?><input name="ref" placeholder="Reference" size="12"><?php endif; ?><button class="btn sm ghost">Mark <?= $step ?></button></form><?php endif; ?>
      <?php if ($s && !$s['paid_on'] && $R['pay']): ?><a href="?view=spends&ym=<?= h($d['ym']) ?>#s<?= (int)$s['id'] ?>">Record payment</a><?php endif; ?></td></tr>
<?php endforeach; ?><?= $open ? '' : '<tr><td colspan="6" class="muted">Nothing due.</td></tr>' ?></tbody></table>
<p class="muted">Payments follow payroll's locked months on Spends; returns and tasks are marked here. Due dates come from Due rules.</p></section>

<?php elseif ($view === 'links'): $P = st_portals($db, $R['manage']); ?>
<?php foreach (ST_AUDIENCE as $ak => $al): $list = array_filter($P, fn($p) => $p['audience'] === $ak); if (!$list) continue; ?>
<section class="card"><h2><?= h($al) ?></h2><table><tbody><?php foreach ($list as $p): ?><tr><td><b><?= h($S[$p['scheme']] ?? $p['scheme']) ?></b><?= $p['state'] !== '' ? ' · ' . h($p['state']) : '' ?></td>
  <td><a href="<?= h($p['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($p['name']) ?></a><?= (int)$p['checked'] ? '' : ' <small class="muted">(address to confirm)</small>' ?><?= (int)$p['active'] ? '' : ' <span class="pill off">Off</span>' ?><br><small class="muted"><?= h($p['note']) ?></small></td>
  <?php if ($R['manage']): ?><td><details><summary>Change</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="portal"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><?= $schemeSel($p['scheme']) ?> <?= $stateSel('state', $p['state']) ?> <?= $pick(ST_AUDIENCE, 'audience', $p['audience']) ?>
    <input name="name" value="<?= h($p['name']) ?>" aria-label="Name"><input name="url" value="<?= h($p['url']) ?>"><input name="note" value="<?= h($p['note']) ?>"><label class="tick"><input type="checkbox" name="checked" value="1"<?= (int)$p['checked'] ? ' checked' : '' ?>> Address checked</label> <label class="tick"><input type="checkbox" name="off" value="1"<?= (int)$p['active'] ? '' : ' checked' ?>> Switch off</label> <button class="btn sm">Save</button></form></details></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></section>
<?php endforeach; ?>
<?php if ($R['manage']): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="portal"><h2>Add a link</h2><div class="grid"><?= $schemeSel() ?><?= $stateSel('state') ?><?= $pick(ST_AUDIENCE, 'audience') ?><input name="name" placeholder="Name" required><input name="url" placeholder="https://…" required><input name="note" placeholder="Note"></div><label class="tick"><input type="checkbox" name="checked" value="1"> Address checked</label> <button class="btn sm">Add</button></form><?php endif; ?>

<?php elseif ($view === 'regs'): $G = st_regs($db); ?>
<section class="card"><h2>Registrations &amp; licences</h2><table><tbody><?php foreach ($G as $g): $exp = $g['valid_till'] && $g['valid_till'] <= date('Y-m-d', strtotime('+60 day')); ?><tr><td><?= h($S[$g['scheme']] ?? $g['scheme']) ?><?= $g['state'] !== '' ? ' · ' . h($g['state']) : '' ?></td><td><b><?= h($g['label']) ?></b> <?= $g['number'] !== '' ? h($g['number']) : '<span class="pill port">Not entered</span>' ?>
  <?= $g['valid_till'] ? ' · valid till ' . h(date('j M Y', strtotime($g['valid_till']))) . ($exp ? ' <span class="pill off">Renew</span>' : '') : '' ?><br><small class="muted"><?= h($g['note']) ?></small></td>
  <?php if ($R['manage']): ?><td><details><summary>Change</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="reg"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><?= $schemeSel($g['scheme']) ?> <?= $stateSel('state', $g['state']) ?><input name="label" value="<?= h($g['label']) ?>" aria-label="Label"><input name="number" value="<?= h($g['number']) ?>" placeholder="Number"><input type="date" name="valid_till" value="<?= h((string)$g['valid_till']) ?>" aria-label="Valid till"><input name="note" value="<?= h($g['note']) ?>"><label class="tick"><input type="checkbox" name="off" value="1"> Switch off</label> <button class="btn sm">Save</button></form></details></td><?php endif; ?></tr><?php endforeach; ?>
<?= $G ? '' : '<tr><td class="muted">None yet.</td></tr>' ?></tbody></table></section>
<?php if ($R['manage']): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="reg"><h2>Add a registration</h2><div class="grid"><?= $schemeSel('gen') ?><?= $stateSel('state') ?><input name="label" placeholder="What it is" required><input name="number" placeholder="Number"><input type="date" name="valid_till" aria-label="Valid till"><input name="note" placeholder="Note"></div><button class="btn sm">Add</button></form><?php endif; ?>

<?php elseif ($view === 'spends' && $R['amounts']): $rows = st_spends($db, $ym); $rules = st_due_rules($db); $locked = isset(st_pay_months($db)[$ym]); ?>
<section class="card"><h2>Spends · <?= h(date('F Y', strtotime($ym . '-01'))) ?></h2>
  <?php if (!$locked): ?><p class="muted">Payroll for this month is not confirmed yet; figures come once it is.</p><?php elseif ($R['manage']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="pull"><input type="hidden" name="ym" value="<?= $ym ?>"><button class="btn sm ghost">Bring in payroll's figures again</button></form><?php endif; ?>
  <table><thead><tr><th>Scheme</th><th>People</th><th>Employees</th><th>Employer</th><th>Charges</th><th>Total</th><th>Due</th><th>Where it stands</th></tr></thead><tbody>
  <?php foreach ($rows as $s): $rule = st_rule_for($rules, $s['scheme'], $s['state']); $due = $rule ? st_due_date($rule, $ym) : null; $pulled = $s['pulled'] ? json_decode((string)$s['pulled'], true) : null; ?>
    <tr id="s<?= (int)$s['id'] ?>"><td><?= h($S[$s['scheme']] ?? $s['scheme']) ?><?= $s['state'] !== '' ? ' · ' . h($s['state']) : '' ?><?= $s['src'] === 'edited' ? ' <span class="pill port">Changed</span>' : ($s['src'] === 'manual' ? ' <span class="pill">By hand</span>' : '') ?></td><td><?= (int)$s['people'] ?></td>
      <td><?= $M($s['ee']) ?></td><td><?= $M($s['er']) ?></td><td><?= $M($s['charges']) ?></td><td><b><?= $M(st_total($s)) ?></b><?= st_differs($s, $pulled) ? '<br><small class="muted">Payroll: ' . $M(st_total($pulled)) . '</small>' : '' ?></td>
      <td><?= $due ? h(date('j M', strtotime($due))) : '' ?></td><td><?= h(st_status($s, $due, $today)) ?><?= $s['paid_on'] ? '<br><small>' . h(date('j M Y', strtotime($s['paid_on']))) . ' · ' . h($s['paid_ref']) . ' · ' . $M($s['paid_amt'] ?? st_total($s)) . '</small>' : '' ?></td></tr>
    <tr><td colspan="8"><?php if ($R['pay']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="paid"><input type="hidden" name="ym" value="<?= $ym ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <input type="date" name="paid_on" value="<?= h((string)$s['paid_on']) ?>" max="<?= $today ?>"><input name="paid_ref" placeholder="Challan / reference" value="<?= h($s['paid_ref']) ?>"><input name="paid_amt" placeholder="Amount (blank = total)" value="<?= h((string)$s['paid_amt']) ?>"><?= $pick(['' => 'Mode'] + ST_PAY_MODES, 'paid_mode', (string)$s['paid_mode']) ?><input name="note" placeholder="Reason, to take a payment off"><button class="btn sm ghost">Save payment</button></form><?php endif; ?>
      <?php if ($R['manage']): ?><details><summary>Change figures</summary><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="spendedit"><input type="hidden" name="ym" value="<?= $ym ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <input name="people" value="<?= (int)$s['people'] ?>" size="4"><input name="ee" value="<?= h((string)$s['ee']) ?>"><input name="er" value="<?= h((string)$s['er']) ?>"><input name="charges" value="<?= h((string)$s['charges']) ?>"><input name="note" placeholder="Why it differs" value="<?= h($s['note']) ?>"><button class="btn sm ghost">Save</button><?php if ($pulled): ?> <button class="btn sm ghost" name="back" value="1">Back to payroll's figures</button><?php endif; ?></form></details><?php endif; ?></td></tr>
  <?php endforeach; ?><?= $rows ? '' : '<tr><td colspan="8" class="muted">No lines for this month.</td></tr>' ?></tbody></table></section>
<?php if ($R['manage']): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="spendadd"><input type="hidden" name="ym" value="<?= $ym ?>"><h2>Add a line by hand</h2><p class="muted">For a month before payroll was used here, or a payment payroll does not know.</p>
  <div class="grid"><?= $schemeSel() ?><?= $stateSel('state') ?><input name="people" placeholder="People"><input name="ee" placeholder="Employees' share"><input name="er" placeholder="Employer's share"><input name="charges" placeholder="Charges"><input name="note" placeholder="Note"></div><button class="btn sm">Add</button></form><?php endif; ?>

<?php elseif ($view === 'year' && $R['amounts']): $start = (int)(org('fy_start_month') ?: 1); $y0 = (int)date('Y') - ((int)date('n') < $start ? 1 : 0); $Y = st_year($db, $y0, $start); $used = []; foreach ($Y as $x) $used += $x; ?>
<section class="card"><h2>This year by month</h2><table><thead><tr><th>Month</th><?php foreach (array_keys($used) as $k): ?><th><?= h($S[$k] ?? $k) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($Y as $m => $x): ?><tr><td><?= h(date('M Y', strtotime($m . '-01'))) ?></td><?php foreach (array_keys($used) as $k): ?><td><?= isset($x[$k]) ? $M($x[$k][0]) . ($x[$k][1] + 0.5 < $x[$k][0] ? ' <small class="muted">paid ' . $M($x[$k][1]) . '</small>' : '') : '' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?= $Y ? '' : '<tr><td class="muted">Nothing yet this year.</td></tr>' ?></tbody></table></section>

<?php elseif ($view === 'rules' && $R['manage']): $rules = st_due_rules($db, true);
    $form = function (?array $r) use ($schemeSel, $stateSel, $pick): string {
        $r ??= ['id' => 0, 'scheme' => 'gen', 'state' => '', 'label' => '', 'day' => 15, 'months' => '', 'on_date' => '', 'after_m' => 1, 'kind' => 'pay', 'who' => '', 'note' => '', 'checked' => 0, 'active' => 1];
        return '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="a" value="due"><input type="hidden" name="id" value="' . (int)$r['id'] . '">' . $schemeSel($r['scheme']) . $stateSel('state', $r['state'])
            . '<input name="label" placeholder="Name" value="' . h($r['label']) . '" required><label>Day <input type="number" name="day" min="1" max="31" value="' . (int)$r['day'] . '" style="width:4em"></label>'
            . '<label>Wage months <input name="months" placeholder="every" value="' . h($r['months']) . '" style="width:6em"></label><label>Months after <input type="number" name="after_m" min="0" max="12" value="' . (int)$r['after_m'] . '" style="width:4em"></label>'
            . '<label>or one date <input type="date" name="on_date" value="' . h((string)$r['on_date']) . '"></label>' . $pick(ST_DUE_KINDS, 'kind', $r['kind']) . $pick(['' => 'Who'] + ST_WHO, 'who', $r['who'])
            . '<input name="note" placeholder="Note" value="' . h($r['note']) . '"><label class="tick"><input type="checkbox" name="checked" value="1"' . ((int)$r['checked'] ? ' checked' : '') . '> Date checked</label>'
            . ((int)$r['id'] ? '<label class="tick"><input type="checkbox" name="off" value="1"' . ((int)$r['active'] ? '' : ' checked') . '> Switch off</label>' : '') . '<button class="btn sm">Save</button></form>';
    }; ?>
<section class="card"><h2>Due rules</h2><p class="muted">Day of the month after the wage month (31 = last day; "months after" moves it further), only in the listed wage months, or one date. Seeded from the country rules — check each against the department's own page.</p>
  <?php foreach ($rules as $r): ?><div class="<?= (int)$r['active'] ? '' : 'muted' ?>"><?= $form($r) ?></div><?php endforeach; ?></section>
<section class="card"><h2>Add a due</h2><?= $form(null) ?></section>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
  <?php foreach (['manage_roles' => 'Change links, dues, registrations and spend figures', 'pay_roles' => 'Record payments'] as $k => $lab): ?><p><?= h($lab) ?>: <?php foreach (role_labels() as $rc => $rl): ?><label class="tick"><input type="checkbox" name="<?= $k ?>[]" value="<?= h($rc) ?>"<?= in_array($rc, $set[$k], true) ? ' checked' : '' ?>> <?= h($rl) ?></label> <?php endforeach; ?></p><?php endforeach; ?>
  <p>Kept apart by state (one registration per state): <?php foreach ($S as $k => $l) if (!in_array($k, st_pack()['state_schemes'], true) && isset(st_pack()['map'][$k])): ?><label class="tick"><input type="checkbox" name="by_state[]" value="<?= h($k) ?>"<?= in_array($k, $set['by_state'], true) ? ' checked' : '' ?>> <?= h($l) ?></label> <?php endif; ?></p>
  <label>Follow dues from (blank = the first confirmed payroll month) <input type="month" name="from_ym" value="<?= h($set['from_ym']) ?>"></label>
  <p class="muted">Amounts open only to logins with pay data; payments also need the pay role above.</p><button class="btn sm">Save</button></form>
<?php endif;
ui_foot();
