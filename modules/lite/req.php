<?php
// Lite attendance corrections: ask for a day (same rules as the full screen, atr_create) and the login's own requests.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/lite.php';
require_once WN_ROOT . '/lib/attendance.php';
require_once WN_ROOT . '/lib/myday.php';
$u = module_require_self('lite');
$db = pdo(); $emp = lite_emp($db, $u);
if (!module_allowed($u, 'attendance') || !$emp) { lite_head('Requests', $u); echo '<p class="e">' . (!$emp ? 'Your login is not linked to an employee record yet — ask HR.' : 'Attendance is not open for you.') . '</p>'; lite_foot($u); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try { atr_create($db, $u, $emp, $_POST); flash('Sent. It goes to your reporting manager, then HR.'); } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('req.php');
}
$s = $db->prepare("SELECT day, in_time, out_time, status FROM attendance_request WHERE (user_id = ? OR emp_code = ?) AND created_at >= ? ORDER BY id DESC LIMIT 30");
$s->execute([(int)$u['id'], (string)$emp['code'], date('Y-m-d', strtotime('-60 day'))]);
lite_head('Requests', $u); ?>
<form method="post" class="c"><?= csrf_field() ?><b>Ask to correct a day</b>
<label>Day <input type="date" name="day" max="<?= date('Y-m-d') ?>" value="<?= h((string)($_GET['day'] ?? '')) ?>" required></label><label>In time <input type="time" name="in_time"></label><label>Out time <input type="time" name="out_time"></label>
<label>Reason <select name="reason" required><option value="">—</option><?php foreach (ATR_REASONS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></label><label>Note <input name="note" maxlength="300"></label><button class="b">Send</button></form>
<div class="c"><b>Your requests</b><table><?php foreach ($s->fetchAll() as $r): ?><tr><td><?= h($r['day']) ?></td><td><?= h(trim(($r['in_time'] ?? '') . ' ' . ($r['out_time'] ?? ''))) ?></td><td class="m"><?= h(md_stage('att', $r)['line']) ?></td></tr><?php endforeach; ?></table></div>
<?php lite_foot($u);
