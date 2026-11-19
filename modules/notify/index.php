<?php
// Notifications — turn them on / off for this device, send a test, and the login's own notices (inbox).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/push.php';
$u = module_require_self('notify');
$db = pdo(); $uid = (int)$u['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { check_csrf(); notice_read_all($db, $uid); flash('All marked read.'); redirect('index.php'); }
$rows = notice_list($db, $uid); $base = wn_base();
ui_head('Notifications', $u, 'notify'); ?>
<div class="card" data-push-box data-csrf="<?= h(csrf_token()) ?>" data-uid="<?= $uid ?>" data-base="<?= h($base) ?>"><p class="muted">Loading…</p></div>
<noscript><div class="card">Turning notifications on needs JavaScript.</div></noscript>
<div class="card"><div class="row" style="justify-content:space-between"><h2>My notices</h2><?php if (notice_unread($db, $uid)): ?><form method="post"><?= csrf_field() ?><button class="btn sm ghost">Mark all read</button></form><?php endif; ?></div>
<?php if (!$rows): ?><p class="muted">No notices yet.</p><?php endif; ?>
<table><?php foreach ($rows as $n): ?><tr<?= $n['read_at'] ? ' class="muted"' : '' ?>><td><?= $n['url'] !== '' ? '<a href="' . h($base . $n['url']) . '">' . h($n['title']) . '</a>' : h($n['title']) ?><div class="muted"><?= h($n['body']) ?></div></td><td class="muted"><?= h((string)$n['created_at']) ?></td></tr><?php endforeach; ?></table></div>
<script src="<?= $base ?>assets/push.js?v=<?= h(WN_VERSION) ?>" defer></script>
<?php ui_foot();
