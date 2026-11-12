<?php
// Attendance requests — ask to correct a day; manager / HR decide; HR adds directly; month close for payroll.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/attendance.php';
$u = module_require('attendance');
$db = pdo();
$emp = !empty($u['emp_code']) ? emp_by_code($db, (string)$u['emp_code']) : null;
$isHr = atr_is_hr($u);
$canClose = atr_can_close($u);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    try {
        if ($a === 'ask' && $emp) { atr_create($db, $u, $emp, $_POST); flash('Sent. It goes to your reporting manager, then HR.'); }
        elseif (in_array($a, ['approve', 'reject', 'withdraw'], true)) { flash('Saved: ' . strtolower(ATR_STATUS[atr_act($db, $u, (int)$_POST['id'], $a, (string)($_POST['note'] ?? ''))]) . '.'); }
        elseif ($a === 'hradd') {
            $t = emp_by_code($db, (string)($_POST['code'] ?? ''));
            if (!$t) throw new RuntimeException('Pick the person.');
            atr_hr_add($db, $u, $t, $_POST); flash('Attendance added.');
        }
        elseif ($a === 'close') { atr_month_close($db, $u, (string)$_POST['ym'], (string)($_POST['note'] ?? '')); flash('Month closed for payroll.'); }
        elseif ($a === 'reopen') { atr_month_reopen($db, $u, (string)$_POST['ym'], (string)($_POST['note'] ?? '')); flash('Month reopened.'); }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('requests.php');
}

$w = atr_waiting($db, $u);
$openFrom = atr_open_from_db($db);
$names = array_column($db->query('SELECT code, name FROM employee')->fetchAll(), 'name', 'code');
$who = fn($id) => $id ? ($db->query('SELECT name FROM users WHERE id = ' . (int)$id)->fetchColumn() ?: '') : '';
$card = function (array $r, bool $act) use ($names, $who) {
    $t = trim(($r['in_time'] ? 'in ' . substr($r['in_time'], 0, 5) : '') . ' ' . ($r['out_time'] ? 'out ' . att_hm($r['out_time'] . ':00') : ''));
    $o = '<div class="mod"><b>' . h(($names[$r['emp_code']] ?? $r['emp_code']) . ' · ' . date('D j M', strtotime($r['day']))) . '</b> — ' . h($t) . ' · ' . h(ATR_REASONS[$r['reason']] ?? $r['reason'])
       . ($r['note'] !== '' ? ' · <i>' . h($r['note']) . '</i>' : '') . ' <span class="pill port">' . h(ATR_STATUS[$r['status']]) . ($r['status'] === 'manager' && $r['manager_id'] ? ': ' . h($who($r['manager_id'])) : '') . '</span>';
    if ($act) $o .= '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input name="note" placeholder="Note (needed to reject)" maxlength="300">'
        . '<button class="btn sm" name="a" value="approve">' . ($r['status'] === 'manager' && $GLOBALS['__atr_hr_skip'] ? 'Approve (skip manager)' : 'Approve') . '</button><button class="btn sm ghost" name="a" value="reject">Reject</button></form>';
    return $o . '</div>';
};
ui_head('Attendance requests', $u, 'attendance');
?>
<nav class="row"><a class="btn sm ghost" href="index.php">My attendance</a><a class="btn sm ghost" href="punch.php">Punch</a></nav>

<?php if ($w['mine_mgr']): $GLOBALS['__atr_hr_skip'] = false; ?><section class="card"><h2>Waiting for you (your team) · <?= count($w['mine_mgr']) ?></h2><?php foreach ($w['mine_mgr'] as $r) echo $card($r, true); ?></section><?php endif; ?>
<?php if ($isHr): $GLOBALS['__atr_hr_skip'] = false; ?>
<section class="card"><h2>Waiting for HR · <?= count($w['hr']) ?></h2><?php foreach ($w['hr'] as $r) echo $card($r, true); ?><?= $w['hr'] ? '' : '<p class="muted">Nothing waiting.</p>' ?></section>
<?php if ($w['with_mgr']): $GLOBALS['__atr_hr_skip'] = true; ?><section class="card"><details><summary><h2 style="display:inline">With reporting managers · <?= count($w['with_mgr']) ?></h2></summary>
  <p class="muted">You may decide these here; the manager step is then skipped.</p><?php foreach ($w['with_mgr'] as $r) echo $card($r, true); ?></details></section><?php endif; ?>
<?php endif; ?>

<?php if ($emp):
    $mine = $db->prepare('SELECT * FROM attendance_request WHERE emp_code = ? ORDER BY id DESC LIMIT 30'); $mine->execute([$emp['code']]); $mine = $mine->fetchAll(); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="ask"><h2>Ask to correct a day</h2>
  <div class="grid">
    <label>Day <input type="date" name="day" min="<?= h(max($openFrom, (string)$emp['doj'])) ?>" max="<?= date('Y-m-d') ?>" value="<?= h((string)($_GET['day'] ?? '')) ?>" required></label>
    <label>In time <input type="time" name="in_time"></label><label>Out time <input type="time" name="out_time"></label>
    <label>Reason <select name="reason" required><option value="">—</option><?php foreach (ATR_REASONS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
    <label>Note <input name="note" maxlength="300"></label></div>
  <p class="muted">From <?= h(date('j M', strtotime($openFrom))) ?> up to today. It goes to your reporting manager, then HR.</p><button class="btn sm">Send</button></form>
<section class="card"><h2>Your requests</h2><?php if (!$mine): ?><p class="muted">None yet.</p><?php endif; foreach ($mine as $r): ?>
  <div class="mod"><?= h(date('D j M', strtotime($r['day'])) . ' — ' . trim(($r['in_time'] ? 'in ' . substr($r['in_time'], 0, 5) : '') . ' ' . ($r['out_time'] ? 'out ' . att_hm($r['out_time'] . ':00') : ''))) ?>
  <span class="pill <?= $r['status'] === 'approved' ? 'on' : ($r['status'] === 'rejected' ? 'off' : 'port') ?>"><?= h(ATR_STATUS[$r['status']]) ?></span><?= $r['mgr_skipped'] ? ' <small class="muted">manager skipped</small>' : '' ?>
  <?= $r['hr_note'] . $r['mgr_note'] !== '' ? ' <small class="muted">' . h(trim($r['mgr_note'] . ' ' . $r['hr_note'])) . '</small>' : '' ?>
  <?php if (in_array($r['status'], ['manager', 'hr'], true) && (int)$r['user_id'] === (int)$u['id']): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost" name="a" value="withdraw">Withdraw</button></form><?php endif; ?></div>
<?php endforeach; ?></section>
<?php endif; ?>

<?php if ($canClose): ?>
<div class="grid2">
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="hradd"><h2>Add attendance for a person</h2>
  <div class="grid"><label>Person <select name="code" required><option value="">—</option><?php foreach (emp_on_roll($db, $u) as $p): if (strcasecmp($p['code'], (string)($u['emp_code'] ?? '')) === 0) continue; ?><option value="<?= h($p['code']) ?>"><?= h($p['name'] . ' · ' . $p['code']) ?></option><?php endforeach; ?></select></label>
  <label>Day <input type="date" name="day" max="<?= date('Y-m-d') ?>" required></label><label>In <input type="time" name="in_time"></label><label>Out <input type="time" name="out_time"></label>
  <label>Reason <select name="reason" required><?php foreach (ATR_REASONS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label><label>Note <input name="note" maxlength="300"></label></div>
  <p class="muted">Approved at once, also in a closed month. Never for your own code.</p><button class="btn sm">Add</button></form>
<section class="card"><h2>Month close for payroll</h2>
  <table><tbody><?php for ($i = 1; $i <= 6; $i++): $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $closed = atr_month_closed($db, $ym); $blk = $closed ? 0 : atr_month_blockers($db, $ym); ?>
  <tr><td><?= h(date('F Y', strtotime($ym . '-01'))) ?></td><td><?= $closed ? '<span class="pill off">Closed</span>' : '<span class="pill on">Open</span>' ?><?= $blk ? ' <span class="pill port">' . $blk . ' waiting</span>' : '' ?></td>
  <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="ym" value="<?= $ym ?>">
    <?php if (!$closed): ?><input name="note" placeholder="Note"><button class="btn sm" name="a" value="close"<?= $blk ? ' disabled' : '' ?>>Close</button>
    <?php elseif (is_superadmin($u)): ?><input name="note" placeholder="Reason to reopen" required><button class="btn sm ghost" name="a" value="reopen">Reopen</button><?php endif; ?></form></td></tr>
  <?php endfor; ?></tbody></table>
  <p class="muted">Closed = no new correction requests for that month. Only the Super Admin reopens.</p></section>
</div>
<?php endif;
ui_foot();
