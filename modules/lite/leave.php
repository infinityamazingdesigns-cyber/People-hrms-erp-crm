<?php
// Lite leave: apply (same rules as the full screen, lv_create), the login's own applications, withdraw a waiting one.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/lite.php';
require_once WN_ROOT . '/lib/attendance.php';
require_once WN_ROOT . '/lib/myday.php';
$u = module_require_self('lite');
$db = pdo(); $emp = lite_emp($db, $u); $set = lv_settings();
if (!module_allowed($u, 'leave') || !$emp) { lite_head('Leave', $u); echo '<p class="e">' . (!$emp ? 'Your login is not linked to an employee record yet — ask HR.' : 'Leave is not open for you.') . '</p>'; lite_foot($u); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (($_POST['a'] ?? '') === 'cancel') { lv_act($db, $u, (int)($_POST['id'] ?? 0), 'cancel'); flash('Withdrawn.'); }
        else { $id = lv_create($db, $u, $emp, $_POST); flash('Applied (#' . $id . '). It goes to your reporting manager, then HR.'); }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('leave.php');
}
$types = lv_types($db); $names = array_column(lv_types($db, false), 'name', 'code');
$s = $db->prepare('SELECT id, from_date, to_date, days, leave_type, req_type, status, wd_status FROM leave_request WHERE emp_code = ? AND to_date >= ? ORDER BY from_date DESC LIMIT 30');
$s->execute([(string)$emp['code'], date('Y-m-d', strtotime('-90 day'))]);
lite_head('Leave', $u); ?>
<form method="post" class="c"><?= csrf_field() ?><b>Apply for leave</b>
<label>From <input type="date" name="from" required></label><label>To (blank = one day) <input type="date" name="to"></label>
<label>Part of the day (one day) <select name="session"><?php foreach (LV_SESSIONS as $k => $l): if (in_array($k, LV_TIMED, true)) continue; ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
<?php if ($set['choose_type']): ?><label>Leave type <select name="type"><option value="">Let HR decide</option><?php foreach ($types as $t): ?><option value="<?= h($t['code']) ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label>Reason <input name="reason" maxlength="500" required></label><button class="b">Apply</button>
<p class="m">Late coming, early going, short leave and handing over work are on the full site.</p></form>
<div class="c"><b>Your leave</b><table><?php foreach ($s->fetchAll() as $r): ?><tr><td><?= h(date('j M', strtotime($r['from_date'])) . ($r['to_date'] > $r['from_date'] ? ' – ' . date('j M', strtotime($r['to_date'])) : '')) ?> · <?= h(rtrim(rtrim((string)$r['days'], '0'), '.')) ?>d</td>
<td><?= h($names[$r['leave_type'] ?: $r['req_type']] ?? '') ?></td><td class="m"><?= h(md_stage('leave', $r)['line']) ?></td>
<td><?php if (in_array($r['status'], ['manager', 'hr'], true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="b">Withdraw</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>
<?php lite_foot($u);
