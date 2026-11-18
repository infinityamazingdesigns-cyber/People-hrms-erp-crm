<?php
// Visitors for me — the person being visited sees who waits for them (and who came this week) and lets them in or declines.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/visitors.php';
$u = module_require_self('visitors');
$db = pdo();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try { vis_set_approval($db, $u, (int)($_POST['id'] ?? 0), (string)($_POST['ans'] ?? ''), (string)($_POST['note'] ?? ''), null, true); flash($_POST['ans'] === 'approved' ? 'Reception will let them in.' : 'Reception is told you cannot meet them.'); }
    catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('mine.php');
}
$rows = vis_for_host($db, (int)$u['id']);
ui_head('Visitors for me', $u, 'visitors'); ?>
<div class="card"><h2>Visitors for me</h2><?php if (!$rows): ?><p class="muted">Nobody waiting for you.</p><?php endif; ?>
<table><?php foreach ($rows as $r): ?><tr><td><b><?= h($r['name']) ?></b><div class="muted"><?= h(implode(' · ', array_filter([$r['designation'], $r['company'], $r['category']]))) ?></div><?= $r['purpose'] !== '' ? '<div>' . h($r['purpose']) . '</div>' : '' ?></td>
<td><?= h(VIS_STATUS[$r['status']] ?? '') ?><?= $r['status'] === 'expected' ? ' · ' . h((string)$r['exp_on']) : '' ?><?= $r['status'] === 'waiting' ? ' · ' . h(VIS_APPROVAL[$r['approval']]) : '' ?></td>
<td><?php if ($r['status'] === 'waiting' && $r['approval'] === 'pending'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="note" placeholder="Message to reception"><button class="btn sm" name="ans" value="approved">Let them in</button><button class="btn sm ghost" name="ans" value="rejected">I can't meet them</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>
<?php ui_foot();
