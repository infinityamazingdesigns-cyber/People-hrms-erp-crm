<?php
// My assets — only the login's own items (by its linked employee code): confirm you hold them, report an issue.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/assets.php';
$u = module_require_self('assets');
$db = pdo();
$code = (string)($u['emp_code'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (($_POST['a'] ?? '') === 'ack') flash(as_ack($db, $u, (array)($_POST['ids'] ?? [])) . ' item(s) confirmed.');
        if (($_POST['a'] ?? '') === 'report') { as_report($db, $u, (int)$_POST['allot'], (string)$_POST['type'], (string)($_POST['desc'] ?? ''), (string)($_POST['when'] ?? '')); flash('Reported. HR will get back to you.'); }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('mine.php');
}
$m = as_mine($db, $code); $audit = as_audit_open($db);
ui_head('My assets', $u, 'assets'); ?>
<?php if ($code === ''): ?><section class="card"><p>Your login is not linked to an employee record yet — ask HR.</p></section><?php ui_foot(); exit; endif; ?>
<?php if ($audit): ?><p class="flash err">Asset audit "<?= h($audit['label']) ?>"<?= $audit['due_on'] ? ' — please confirm by ' . h(date('j M Y', strtotime($audit['due_on']))) : '' ?>.</p><?php endif; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="ack"><h2>With you</h2>
  <table><tbody><?php foreach ($m['held'] as $r): ?><tr><td><?php if ($r['needs_ack']): ?><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" checked><?php else: ?><span class="pill on">Confirmed</span><?php endif; ?></td>
    <td><b><?= h($r['cat']) ?></b> · <?= h($r['tag']) ?><br><small class="muted"><?= h(trim($r['make'] . ' ' . $r['model'])) ?><?= $r['serial'] !== '' ? ' · ' . h($r['serial']) : '' ?><?= $r['imei1'] !== '' ? ' · IMEI ' . h($r['imei1']) : '' ?><?= $r['sim_no'] !== '' ? ' · ' . h($r['sim_no']) : '' ?><?= $r['card_last4'] !== '' ? ' · ending ' . h($r['card_last4']) : '' ?></small></td>
    <td>Since <?= h(date('j M Y', strtotime($r['given_on']))) ?><?= $r['accessories'] !== '' ? '<br><small>With ' . h($r['accessories']) . '</small>' : '' ?></td></tr><?php endforeach; ?>
  <?= $m['held'] ? '' : '<tr><td class="muted">Nothing is with you.</td></tr>' ?></tbody></table>
  <?php if (array_filter($m['held'], fn($r) => $r['needs_ack'])): ?><button class="btn sm">I have the ticked items</button><?php endif; ?></form>
<?php if ($m['held']): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="report"><h2>Report an issue</h2><div class="grid">
  <select name="allot"><?php foreach ($m['held'] as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['cat'] . ' · ' . $r['tag']) ?></option><?php endforeach; ?></select>
  <select name="type"><?php foreach (AS_ISSUE_TYPES as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select><input type="date" name="when" max="<?= date('Y-m-d') ?>"><input name="desc" placeholder="What happened" required maxlength="600"></div>
  <p class="muted">Lost or stolen phone or SIM: ask the operator to block the SIM and change the passwords of work accounts used on it.</p><button class="btn sm">Report</button></form><?php endif; ?>
<?php if ($m['issues']): ?><section class="card"><h2>Your reports</h2><table><tbody><?php foreach ($m['issues'] as $i): ?><tr><td><?= h(date('j M Y', strtotime($i['created_at']))) ?></td><td><?= h($i['tag'] . ' · ' . $i['type']) ?><br><small class="muted"><?= h($i['description']) ?></small></td><td><?= $i['status'] === 'open' ? '<span class="pill port">Open</span>' : '<span class="pill on">' . h(AS_OUTCOMES[$i['outcome']] ?? 'Closed') . '</span>' ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
<?php if ($m['returned']): ?><section class="card"><h2>Returned</h2><table><tbody><?php foreach ($m['returned'] as $r): ?><tr><td><?= h($r['cat'] . ' · ' . $r['tag']) ?></td><td><?= h(date('j M Y', strtotime($r['given_on']))) ?> – <?= h(date('j M Y', strtotime($r['returned_on']))) ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
<?php ui_foot();
