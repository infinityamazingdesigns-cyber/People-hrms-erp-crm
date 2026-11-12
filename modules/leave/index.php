<?php
// Leave — apply, my requests, approvals (manager / HR), withdrawals, balances, types and settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/attendance.php';
$u = module_require('leave');
$db = pdo();
$me = !empty($u['emp_code']) ? emp_by_code($db, (string)$u['emp_code']) : null;
$isHr = lv_is_hr($u); $isSuper = lv_super($u);
$set = lv_settings();
$types = lv_types($db);
$typeName = array_column(lv_types($db, false), 'name', 'code');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $back = (string)($_POST['back'] ?? 'index.php');
    try {
        switch ($a) {
            case 'apply':
                $for = strtoupper(trim((string)($_POST['for'] ?? '')));
                $emp = $for !== '' ? emp_by_code($db, $for) : $me;
                if (!$emp) throw new RuntimeException($for !== '' ? 'Pick the person.' : 'Your login is not linked to an employee record.');
                $id = lv_create($db, $u, $emp, $_POST, $isHr && !empty($_POST['recorded']));
                flash('Applied (#' . $id . ').' . (!empty($_POST['recorded']) ? ' Recorded as approved.' : ' It goes to the reporting manager, then HR.'));
                break;
            case 'approve': case 'reject': case 'cancel':
                flash('Saved: ' . strtolower(LV_STATUS[lv_act($db, $u, (int)$_POST['id'], $a, (string)($_POST['note'] ?? ''), (string)($_POST['type'] ?? ''))]) . '.');
                break;
            case 'wdask': lv_withdraw_request($db, $u, (int)$_POST['id'], (string)($_POST['reason'] ?? '')); flash('Withdrawal asked. The leave stays approved until HR agrees.'); break;
            case 'agree': case 'keep': case 'takeback': lv_withdraw_act($db, $u, (int)$_POST['id'], $a, (string)($_POST['note'] ?? '')); flash('Saved.'); break;
            case 'hrwd': lv_hr_withdraw($db, $u, (int)$_POST['id'], (string)($_POST['note'] ?? '')); flash('Withdrawn.'); break;
            case 'settype': lv_set_type($db, $u, (int)$_POST['id'], (string)($_POST['type'] ?? '')); flash('Leave type saved.'); break;
            case 'adjust':
                $emp = emp_by_code($db, (string)($_POST['code'] ?? ''));
                if (!$emp) throw new RuntimeException('Pick the person.');
                lv_adjust($db, $u, $emp, (string)$_POST['type'], (float)$_POST['days'], (string)($_POST['note'] ?? '')); flash('Balance changed.'); break;
            case 'typesave': lv_type_save($db, $u, $_POST); flash('Leave type saved.'); break;
            case 'typeoff': lv_type_off($db, $u, (string)$_POST['code'], (string)($_POST['reason'] ?? '')); flash('Switched off.'); break;
            case 'settings':
                if (!$isSuper) throw new RuntimeException('Only the Super Admin or HR Manager changes leave settings.');
                [$new, $err] = lv_settings_check($_POST);
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['leave'] ?? ['roles' => module_roles('leave')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['leave', 1, implode(',', $st['roles'] ?: module_roles('leave')), json_encode($new), (int)$u['id']]);
                audit((int)$u['id'], 'module_settings', 'leave');
                flash('Leave settings saved.');
                break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect(preg_match('/^index\.php(\?[\w=&%-]*)?$/', $back) ? $back : 'index.php');
}

$L = lv_lists($db, $u);
$hrWait = count($L['hr']) + count($L['wd_hr']);
$myWait = count($L['manager']) + count($L['wd_manager']);
$views = ['apply' => 'Apply', 'mine' => 'My leave'] + ($myWait || $L['handover'] ? ['team' => 'My team · ' . $myWait] : [])
       + ($isHr ? ['hr' => 'HR approvals · ' . $hrWait, 'balances' => 'Balances'] : []) + ($isSuper ? ['types' => 'Types & settings'] : []);
$view = isset($views[$_GET['view'] ?? '']) ? $_GET['view'] : ($isHr && $hrWait ? 'hr' : ($myWait ? 'team' : 'apply'));
$back = 'index.php?view=' . $view;
$hid = fn() => csrf_field() . '<input type="hidden" name="back" value="' . h($back) . '">';
$typeSel = function (string $name, string $cur = '', bool $blank = true) use ($types) {
    $o = '<select name="' . $name . '">' . ($blank ? '<option value="">—</option>' : '');
    foreach ($types as $t) $o .= '<option value="' . h($t['code']) . '"' . ($t['code'] === $cur ? ' selected' : '') . '>' . h($t['name']) . '</option>';
    return $o . '</select>';
};
$card = function (array $r, string $actions = '') use ($typeName) {
    $st = LV_STATUS[$r['status']] . ($r['wd_status'] && isset(LV_WD_STATUS[$r['wd_status']]) && $r['status'] === 'approved' ? ' · ' . LV_WD_STATUS[$r['wd_status']] : '');
    $type = $r['leave_type'] ?: $r['req_type'];
    return '<div class="mod"><b>' . h(($r['name'] ?? $r['emp_code']) . ' · ' . lv_span_text($r)) . '</b>'
        . ((float)$r['days'] > 0 ? ' · ' . rtrim(rtrim((string)$r['days'], '0'), '.') . ' day(s)' : '')
        . ($type ? ' · ' . h($typeName[$type] ?? $type) . ($r['leave_type'] ? '' : ' <small class="muted">(asked)</small>') : '')
        . ' <span class="pill ' . ($r['status'] === 'approved' ? 'on' : (in_array($r['status'], ['rejected', 'cancelled', 'withdrawn'], true) ? 'off' : 'port')) . '">' . h($st) . '</span>'
        . ($r['mgr_skipped'] ? ' <small class="muted">manager skipped</small>' : '')
        . '<br><small>' . h($r['reason']) . ($r['wd_reason'] ? ' · withdrawal: ' . h($r['wd_reason']) : '') . (trim($r['mgr_note'] . ' ' . $r['hr_note']) !== '' ? ' · <i>' . h(trim($r['mgr_note'] . ' ' . $r['hr_note'])) . '</i>' : '') . '</small>'
        . $actions . '</div>';
};
$decide = fn(array $r, bool $hr) => '<form method="post" class="row">' . $hid() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
    . ($hr && !in_array($r['from_session'], LV_TIMED, true) ? '<label>Type ' . $typeSel('type', (string)$r['req_type']) . '</label>' : '')
    . '<input name="note" placeholder="Note (needed to reject)" maxlength="500"><button class="btn sm" name="a" value="approve">' . ($r['status'] === 'manager' && $hr ? 'Approve (skip manager)' : 'Approve') . '</button>'
    . '<button class="btn sm ghost" name="a" value="reject">Reject</button></form>';
$wdDecide = fn(array $r) => '<form method="post" class="row">' . $hid() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input name="note" placeholder="Note (needed to keep)">'
    . '<button class="btn sm" name="a" value="agree">Agree to withdraw</button><button class="btn sm ghost" name="a" value="keep">Keep the leave</button></form>';

ui_head('Leave', $u, 'leave');
?>
<nav class="row"><?php foreach ($views as $k => $l): ?><a class="btn sm<?= $view === $k ? '' : ' ghost' ?>" href="?view=<?= $k ?>"><?= h($l) ?></a><?php endforeach; ?></nav>

<?php if ($view === 'apply'):
    $people = $isHr ? array_values(array_filter(emp_on_roll($db, $u), fn($p) => strcasecmp($p['code'], (string)($u['emp_code'] ?? '')) !== 0)) : [];
    if (!$me && !$people): ?><section class="card"><p>Your login is not linked to an employee record yet, so you cannot apply. Speak to HR.</p></section>
<?php else:
    $bal = $me ? lv_balance_for($db, $me) : null; ?>
<?php if ($bal && ($set['show_balance'] || $isHr)): ?><section class="card"><h2>Your balance · <?= h(date('M Y', strtotime($bal['year'][0])) . ' – ' . date('M Y', strtotime($bal['year'][1]))) ?></h2>
  <p><?php foreach ($bal['rows'] as $c => $b): if ($b['left'] === null) continue; ?><span class="pill"><?= h($typeName[$c] ?? $c) ?>: <b><?= $b['left'] - $b['waiting'] ?></b> left<?= $b['waiting'] ? ' <small>(' . $b['waiting'] . ' waiting)</small>' : '' ?></span> <?php endforeach; ?></p></section><?php endif; ?>
<form method="post" class="card" id="lvf"><?= $hid() ?><input type="hidden" name="a" value="apply"><h2>Apply for leave</h2>
  <div class="grid">
  <?php if ($people): ?><label>For <select name="for"><?php if ($me): ?><option value="">Myself</option><?php endif; ?><?php foreach ($people as $p): ?><option value="<?= h($p['code']) ?>"><?= h($p['name'] . ' · ' . $p['code']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label>From <input type="date" name="from" required></label><label>To <input type="date" name="to"></label>
    <label id="oneDay">Part of the day <select name="session"><?php foreach (LV_SESSIONS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
    <label id="fs" hidden>First day <select name="from_session"><option value="full">Full day</option><option value="second">Second half</option></select></label>
    <label id="ts" hidden>Last day <select name="to_session"><option value="full">Full day</option><option value="first">First half</option></select></label>
    <label id="tf" hidden>Time <input type="time" name="time_from"></label><label id="tt" hidden>Back at <input type="time" name="time_to"></label>
    <?php if ($set['choose_type'] || $isHr): ?><label id="ty">Leave type <?= $typeSel('type') ?></label><?php endif; ?>
    <label>Work handed over to <select name="handover"><option value="">—</option><?php foreach (lv_handover_people($db, (int)$u['id']) as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name'] . ($p['dept'] !== '' ? ' · ' . $p['dept'] : '')) ?></option><?php endforeach; ?></select></label>
  </div>
  <label>Reason <input name="reason" maxlength="500" required></label>
  <?php if ($isHr): ?><p><label class="tick"><input type="checkbox" name="recorded" value="1"> Already approved outside (paper / message) — record it as approved with the type above</label> <input name="note" placeholder="Where it was approved" maxlength="200"></p><?php endif; ?>
  <p class="muted">Up to <?= (int)$set['back_days'] ?> days back and <?= (int)$set['ahead_days'] ?> ahead.<?= $set['count_off_days'] ? '' : ' Weekly offs and holidays inside the dates are not counted.' ?></p>
  <button class="btn">Apply</button></form>
<script>
(() => { const f = document.getElementById('lvf'), q = n => f.querySelector('[name=' + n + ']'), show = (id, on) => document.getElementById(id).hidden = !on;
  const draw = () => { const one = !q('to').value || q('to').value === q('from').value, s = q('session').value, timed = one && ['late', 'early', 'out'].includes(s);
    show('oneDay', one); show('fs', !one); show('ts', !one); show('tf', timed); show('tt', timed && s === 'out'); const t = document.getElementById('ty'); if (t) t.hidden = timed; };
  f.addEventListener('change', draw); draw(); })();
</script>
<?php endif; ?>

<?php elseif ($view === 'mine'): ?>
<section class="card"><h2>My leave</h2><?php if (!$L['mine']): ?><p class="muted">None yet.</p><?php endif;
  foreach ($L['mine'] as $r) {
      $act = '';
      if (in_array($r['status'], ['manager', 'hr'], true)) $act = '<form method="post" class="row">' . $hid() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sm ghost" name="a" value="cancel">Withdraw application</button></form>';
      elseif ($r['status'] === 'approved' && !in_array($r['wd_status'], ['manager', 'hr'], true)) $act = '<details><summary class="muted">Request to withdraw</summary><form method="post" class="row">' . $hid() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input name="reason" placeholder="Why" required maxlength="500"><button class="btn sm ghost" name="a" value="wdask">Send</button></form></details>';
      elseif ($r['status'] === 'approved' && (int)$r['wd_by'] === (int)$u['id']) $act = '<form method="post" class="row">' . $hid() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sm ghost" name="a" value="takeback">Take back the withdrawal request</button></form>';
      echo $card($r, $act);
  } ?></section>
<?php if ($L['byme']): ?><section class="card"><h2>Applied by you for others</h2><?php foreach ($L['byme'] as $r) echo $card($r, in_array($r['status'], ['manager', 'hr'], true) ? '<form method="post" class="row">' . $hid() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sm ghost" name="a" value="cancel">Withdraw</button></form>' : ''); ?></section><?php endif; ?>

<?php elseif ($view === 'team'): ?>
<section class="card"><h2>Waiting for you · <?= count($L['manager']) ?></h2><?php foreach ($L['manager'] as $r) echo $card($r, $decide($r, false)); ?><?= $L['manager'] ? '' : '<p class="muted">Nothing waiting.</p>' ?></section>
<?php if ($L['wd_manager']): ?><section class="card"><h2>Withdrawal requests · <?= count($L['wd_manager']) ?></h2><?php foreach ($L['wd_manager'] as $r) echo $card($r, $wdDecide($r)); ?></section><?php endif; ?>
<?php if ($L['handover']): ?><section class="card"><h2>Work handed over to you</h2><?php foreach ($L['handover'] as $r) echo $card($r); ?></section><?php endif; ?>

<?php elseif ($view === 'hr'): ?>
<section class="card"><h2>Waiting for HR · <?= count($L['hr']) ?></h2><?php foreach ($L['hr'] as $r) echo $card($r, $decide($r, true)); ?><?= $L['hr'] ? '' : '<p class="muted">Nothing waiting.</p>' ?></section>
<?php if ($L['wd_hr']): ?><section class="card"><h2>Withdrawal requests · <?= count($L['wd_hr']) ?></h2><?php foreach ($L['wd_hr'] as $r) echo $card($r, $wdDecide($r)); ?></section><?php endif; ?>
<?php if ($L['with_manager'] || $L['wd_with_manager']): ?><section class="card"><details open><summary><h2 style="display:inline">With reporting managers · <?= count($L['with_manager']) + count($L['wd_with_manager']) ?></h2></summary>
  <p class="muted">You may decide these here; the manager step is then skipped.</p>
  <?php foreach ($L['with_manager'] as $r) echo $card($r, $decide($r, true)); foreach ($L['wd_with_manager'] as $r) echo $card($r, $wdDecide($r)); ?></details></section><?php endif; ?>
<section class="card"><details><summary><h2 style="display:inline">Approved from today · <?= count($L['upcoming']) ?></h2></summary>
  <?php foreach ($L['upcoming'] as $r) echo $card($r, (!in_array($r['from_session'], LV_TIMED, true) ? '<form method="post" class="row">' . $hid() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><label>Type ' . $typeSel('type', (string)$r['leave_type']) . '</label><button class="btn sm ghost" name="a" value="settype">Save type</button></form>' : '')
      . ($isSuper ? '<details><summary class="muted">Withdraw (Super Admin / HR Manager)</summary><form method="post" class="row">' . $hid() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input name="note" placeholder="Note" required><button class="btn sm ghost" name="a" value="hrwd">Withdraw</button></form></details>' : '')); ?></details></section>
<section class="card"><details><summary><h2 style="display:inline">Decided in the last 60 days · <?= count($L['decided']) ?></h2></summary><?php foreach ($L['decided'] as $r) echo $card($r); ?></details></section>

<?php elseif ($view === 'balances'):
    $code = strtoupper((string)($_GET['code'] ?? '')); $people = emp_on_roll($db, $u); ?>
<form class="card row"><input type="hidden" name="view" value="balances"><select name="code"><option value="">Pick a person…</option><?php foreach ($people as $p): ?><option value="<?= h($p['code']) ?>"<?= $p['code'] === $code ? ' selected' : '' ?>><?= h($p['name'] . ' · ' . $p['code']) ?></option><?php endforeach; ?></select><button class="btn sm">Show</button></form>
<?php if ($code !== '' && ($e = emp_by_code($db, $code)) && emp_in_scope($u, $e)): $b = lv_balance_for($db, $e);
    $adj = $db->prepare('SELECT a.*, u.name AS by_name FROM leave_adjust a LEFT JOIN users u ON u.id = a.by_user WHERE a.emp_code = ? ORDER BY a.id DESC LIMIT 50'); $adj->execute([$code]); ?>
<section class="card"><h2><?= h($e['name']) ?> · <?= h(date('M Y', strtotime($b['year'][0])) . ' – ' . date('M Y', strtotime($b['year'][1]))) ?></h2>
  <table><thead><tr><th>Type</th><th>For the year</th><th>Changes</th><th>Taken</th><th>Waiting</th><th>Left</th></tr></thead><tbody>
  <?php foreach ($b['rows'] as $c => $r): ?><tr><td><?= h($typeName[$c] ?? $c) ?></td><td><?= $r['entitled'] === null ? 'No limit' : $r['entitled'] ?></td><td><?= $r['adjusted'] ?></td><td><?= $r['taken'] ?></td><td><?= $r['waiting'] ?></td><td><b><?= $r['left'] === null ? '—' : $r['left'] ?></b></td></tr><?php endforeach; ?></tbody></table>
  <p class="muted">Joining <?= h((string)$e['doj']) ?> · confirmation <?= h((string)($e['confirm_date'] ?: 'not yet')) ?>.</p>
  <?php if ($isSuper && strcasecmp($code, (string)($u['emp_code'] ?? '')) !== 0): ?><form method="post" class="row"><?= $hid() ?><input type="hidden" name="a" value="adjust"><input type="hidden" name="code" value="<?= h($code) ?>"><input type="hidden" name="back" value="index.php?view=balances&code=<?= h(urlencode($code)) ?>">
    <?= $typeSel('type', '', false) ?><input type="number" name="days" step="0.5" placeholder="+ / − days" required><input name="note" placeholder="Reason" required><button class="btn sm">Change balance</button></form><?php endif; ?>
  <?php $rows = $adj->fetchAll(); if ($rows): ?><h3>Changes</h3><table><tbody><?php foreach ($rows as $a): ?><tr><td><?= h(substr((string)$a['at'], 0, 10)) ?></td><td><?= h($typeName[$a['type_code']] ?? $a['type_code']) ?></td><td><?= $a['days'] > 0 ? '+' : '' ?><?= h((string)$a['days']) ?></td><td><?= h($a['note']) ?></td><td><?= h((string)$a['by_name']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</section>
<?php endif; ?>

<?php elseif ($view === 'types'): ?>
<section class="card"><h2>Leave types</h2>
  <table><thead><tr><th>Code</th><th>Name</th><th>Paid</th><th>Days a year</th><th>Rules</th><th></th></tr></thead><tbody>
  <?php foreach (lv_types($db, false) as $t): ?><tr><td><?= h($t['code']) ?></td><td><?= h($t['name']) ?></td><td><?= $t['paid'] ? 'Yes' : 'No' ?></td><td><?= $t['per_year'] === null ? 'No limit' : h(rtrim(rtrim((string)$t['per_year'], '0'), '.')) ?></td>
    <td><small><?= implode(' · ', array_filter([$t['prorate'] ? 'pro-rated' : '', $t['after_confirm'] ? 'after confirmation' : '', $t['on_day'] === 'birthday' ? 'birthday only' : '', $t['apply_choice'] ? '' : 'HR sets only'])) ?></small></td>
    <td><?php if ($t['active']): ?><form method="post" class="row"><?= $hid() ?><input type="hidden" name="a" value="typeoff"><input type="hidden" name="code" value="<?= h($t['code']) ?>"><input name="reason" placeholder="Reason" required><button class="btn sm ghost">Switch off</button></form><?php else: ?><span class="pill off">Off</span> <small><?= h($t['off_reason']) ?></small><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section>
<form method="post" class="card"><?= $hid() ?><input type="hidden" name="a" value="typesave"><h2>Add or change a type</h2>
  <div class="grid"><label>Code <input name="code" maxlength="8" required placeholder="CL"></label><label>Name <input name="name" maxlength="60" required placeholder="Casual leave"></label>
  <label>Days a year <input name="per_year" placeholder="blank = no limit"></label><label>Order <input type="number" name="sort" value="10"></label></div>
  <p><label class="tick"><input type="checkbox" name="paid" value="1" checked> Paid</label> <label class="tick"><input type="checkbox" name="prorate" value="1" checked> Pro-rated from joining</label>
  <label class="tick"><input type="checkbox" name="after_confirm" value="1"> Only after confirmation</label> <label class="tick"><input type="checkbox" name="apply_choice" value="1" checked> People may ask for it</label>
  <label class="tick"><input type="checkbox" name="on_day" value="birthday"> Only on the birthday</label></p>
  <p class="muted">Saving an existing code changes it (and switches it back on). Earlier leave keeps its type.</p><button class="btn sm">Save</button></form>
<form method="post" class="card"><?= $hid() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
  <div class="grid"><label>Days back <input type="number" name="back_days" value="<?= (int)$set['back_days'] ?>"></label><label>Days ahead <input type="number" name="ahead_days" value="<?= (int)$set['ahead_days'] ?>"></label>
  <label>Longest application (days) <input type="number" name="max_span" value="<?= (int)$set['max_span'] ?>"></label></div>
  <p><label class="tick"><input type="checkbox" name="count_off_days" value="1"<?= $set['count_off_days'] ? ' checked' : '' ?>> Count weekly offs and holidays inside a range</label>
  <label class="tick"><input type="checkbox" name="show_balance" value="1"<?= $set['show_balance'] ? ' checked' : '' ?>> Show people their own balance</label>
  <label class="tick"><input type="checkbox" name="choose_type" value="1"<?= $set['choose_type'] ? ' checked' : '' ?>> People choose the type they ask for (HR has the final say)</label></p>
  <p class="muted">The leave year follows Admin → Organisation → year start month. A month closed for payroll takes no new leave.</p><button class="btn sm">Save settings</button></form>
<?php endif;
ui_foot();
