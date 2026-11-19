<?php
// My day — the login's own day. Read only, own data only, never a money figure. Rules in lib/myday.php.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/myday.php';
$u = module_require_self('myday');
$db = pdo(); $today = date('Y-m-d'); $P = md_payload($db, $u, $today); $base = wn_base();
ui_head('My day', $u, 'myday');
$first = explode(' ', trim((string)$u['name']))[0] ?? ''; ?>
<div class="card"><h2><?= h(md_greeting((int)date('G')) . ($first !== '' ? ', ' . $first : '')) ?></h2><p class="muted"><?= h(date('l, j F Y')) ?></p>
<?php if ($P['today'] !== null): $t = $P['today']; ?><p><?= $t['n'] ? 'In at <b>' . h((string)$t['in']) . '</b>' . ($t['out'] ? ' · out at <b>' . h($t['out']) . '</b>' : '') . ' · ' . $t['n'] . ' punch' . ($t['n'] > 1 ? 'es' : '') : 'Not checked in today.' ?>
<?php if (module_allowed($u, 'attendance')): ?> <a class="btn sm" href="<?= h($base) ?>/modules/attendance/punch.php"><?= $t['next'] === 'in' ? 'Check in' : 'Check out' ?></a><?php endif; ?></p>
<?php elseif (!$P['code'] && module_on('attendance')): ?><p class="muted">Your login is not linked to an employee record yet — ask HR.</p><?php endif; ?>
<p class="row" style="flex-wrap:wrap"><?php if ($P['tasks'] !== null): ?><a href="<?= h($base) ?>/modules/tasks/index.php"><b><?= $P['tasks']['n'] ?></b> open tasks<?= $P['tasks']['late'] ? ' · <b>' . $P['tasks']['late'] . ' late</b>' : '' ?><?= $P['tasks']['today'] ? ' · ' . $P['tasks']['today'] . ' due today' : '' ?></a><?php endif; ?>
<?php if ($P['notices']): ?> · <a href="<?= h($base) ?>/modules/notify/index.php"><b><?= $P['notices'] ?></b> unread notices</a><?php endif; ?></p></div>

<div class="card"><h3>Your requests</h3>
<?php foreach ($P['requests'] as $r): [$label, , $link] = MD_KINDS[$r['kind']]; $s = $r['stage']; ?>
<div style="padding:.4em 0;border-bottom:1px solid var(--line,#ddd)"><a href="<?= h($base . '/' . $link) ?>"><b><?= h($label) ?></b><?= $r['what'] !== '' ? ' · ' . h($r['what']) : '' ?></a> — <?= $s['state'] === 'done' ? '✓ ' : ($s['state'] === 'stopped' ? '✕ ' : '') ?><?= h($s['line']) ?> <span class="muted"><?= h(substr($r['at'], 0, 10)) ?></span>
<?php if ($s['steps']): ?><div class="muted" style="font-size:.85em"><?= implode(' → ', array_map(fn($i, $n) => $i < $s['at'] || ($i === $s['at'] && $s['state'] === 'done') ? h($n) : ($i === $s['at'] ? '<b>' . h($n) . '</b>' : '<span style="opacity:.5">' . h($n) . '</span>'), array_keys($s['steps']), $s['steps'])) ?></div><?php endif; ?></div>
<?php endforeach; ?><?php if (!$P['requests']): ?><p class="muted">Nothing open, nothing closed in the last <?= MD_DAYS ?> days.</p><?php endif; ?></div>

<?php if ($P['letters']): ?><div class="card"><h3>Letters issued to you</h3><?php foreach ($P['letters'] as $l): ?><div><a href="<?= h($base) ?>/modules/letters/mine.php"><?= h((string)($l['name'] ?? 'Letter')) ?></a> <span class="muted"><?= h((string)$l['ref']) ?> · <?= h(substr((string)$l['issued_at'], 0, 10)) ?></span></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($P['posts']): ?><div class="card"><h3>Announcements</h3><?php foreach ($P['posts'] as $p): ?><div><a href="<?= h($base) ?>/modules/board/index.php"><?= h($p['title']) ?></a> <span class="muted"><?= h(substr($p['created_at'], 0, 10)) ?></span></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($P['links']): ?><div class="card"><h3>Quick actions</h3><div class="row" style="flex-wrap:wrap"><?php foreach ($P['links'] as [$l, $href]): ?><a class="btn sm ghost" href="<?= h($base . '/' . ltrim($href, '/')) ?>"><?= h($l) ?></a><?php endforeach; ?></div></div><?php endif; ?>
<?php ui_foot();
