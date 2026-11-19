<?php
// Lite home: today's punches, open requests, quick links. Small page for slow internet.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/lite.php';
require_once WN_ROOT . '/lib/myday.php';
$u = module_require_self('lite');
$db = pdo(); $P = md_payload($db, $u, date('Y-m-d')); $b = wn_base();
lite_head('Home', $u); ?>
<div class="c"><b><?= h(md_greeting((int)date('G')) . ', ' . (explode(' ', trim((string)$u['name']))[0] ?? '')) ?></b> <span class="m"><?= h(date('D j M')) ?></span>
<?php if ($P['today'] !== null): $t = $P['today']; ?><p><?= $t['n'] ? 'In ' . h((string)$t['in']) . ($t['out'] ? ' · out ' . h($t['out']) : '') : 'Not checked in today.' ?> <?php if (module_allowed($u, 'attendance')): ?><a class="b" href="punch.php"><?= $t['next'] === 'in' ? 'Check in' : 'Check out' ?></a><?php endif; ?></p><?php endif; ?>
<?php if ($P['tasks'] && $P['tasks']['n']): ?><p><?= $P['tasks']['n'] ?> open tasks<?= $P['tasks']['late'] ? ', ' . $P['tasks']['late'] . ' late' : '' ?></p><?php endif; ?></div>
<?php $open = array_filter($P['requests'], fn($r) => $r['stage']['state'] === 'open'); if ($open): ?><div class="c"><b>Waiting</b><table><?php foreach ($open as $r): ?><tr><td><?= h(MD_KINDS[$r['kind']][0] . ($r['what'] !== '' ? ' · ' . $r['what'] : '')) ?></td><td class="m"><?= h($r['stage']['line']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php lite_foot($u);
