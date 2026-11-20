<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$u = require_login();
migrate(pdo());   // new migrations land on the first page after a deploy
require_once __DIR__ . '/lib/lite.php';
$lr = lite_route(module_on('lite'), $_COOKIE, $_GET);
if ($lr === 'lite') redirect('modules/lite/');
if ($lr === 'full') { lite_pref(false); redirect('index.php'); }

require_once __DIR__ . '/lib/home.php';
ui_head('Home', $u);
$menu = module_menu($u);
$H = hm_payload(pdo(), $u, date('Y-m-d'));
$why = ['late' => 'Late', 'today' => 'Today', 'tomorrow' => 'Tomorrow', 'high' => 'High'];
?>
<section class="hello card">
  <h2><?= h(md_greeting((int)date('G'))) ?>, <?= h(explode(' ', $u['name'])[0]) ?></h2>
  <p><?= h(date('l, j F Y')) ?></p>
  <?php if (is_superadmin($u) && org('setup_done') !== '1'): ?>
    <p class="note">Setup is not finished. <a href="admin/guide.php">Open the setup checklist</a>.</p>
  <?php endif; ?>
</section>
<div class="hm">
  <?php if ($H['punch']): $t = $H['today']; ?>
  <section class="card hm-day"><h3>Today</h3>
    <p><?= $t && $t['in'] ? 'In at <b>' . h($t['in']) . '</b>' . ($t['out'] ? ' · out at <b>' . h($t['out']) . '</b>' : '') : 'Not checked in yet' ?></p>
    <a class="btn sm" href="modules/attendance/punch.php"><?= $t && $t['next'] === 'out' ? 'Check out' : 'Check in' ?></a>
    <a class="btn sm ghost" href="modules/attendance/index.php">My attendance</a></section>
  <?php endif; ?>
  <?php if (module_allowed($u, 'tasks')): ?>
  <section class="card hm-tasks"><h3>Urgent <small><?= count($H['tasks']) ?: '' ?></small></h3>
    <?php if (!$H['tasks']): ?><p class="note">Nothing late, due today or tomorrow, or marked high.</p><?php endif; ?>
    <ul class="hm-list"><?php foreach ($H['tasks'] as $t): ?>
      <li class="hm-<?= h($t['why']) ?>"><form method="post" action="modules/tasks/index.php"><?= csrf_field() ?><input type="hidden" name="a" value="done"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back" value="home"><button class="hm-tick" title="Mark done" aria-label="Mark done: <?= h($t['title']) ?>">✓</button></form>
        <a href="modules/tasks/index.php#t<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a><small><?= h($why[$t['why']]) ?><?= $t['due_date'] ? ' · ' . h(date('j M', strtotime($t['due_date']))) . ($t['due_time'] ? ' ' . h(substr($t['due_time'], 0, 5)) : '') : '' ?></small></li>
    <?php endforeach; ?></ul>
    <a href="modules/tasks/index.php">All my tasks</a></section>
  <?php endif; ?>
  <section class="card hm-wait"><h3>Waiting for you</h3>
    <?php if (!$H['waiting']): ?><p class="note">Nothing waits for you.</p><?php endif; ?>
    <ul class="hm-list"><?php foreach ($H['waiting'] as [$l, $n, $href]): ?><li><a href="<?= h($href) ?>"><?= h($l) ?></a><b><?= (int)$n ?></b></li><?php endforeach; ?></ul>
    <?php if (module_on('myday')): ?><p><a href="modules/myday/index.php">My day</a><?= $H['open'] ? ' · ' . $H['open'] . ' of your requests open' : '' ?><?= $H['notices'] ? ' · ' . $H['notices'] . ' unread notices' : '' ?></p><?php endif; ?></section>
</div>
<?php foreach ($menu as $g => $mods): ?>
  <section class="band"><h3><?= h(MODULE_GROUPS[$g] ?? $g) ?></h3><div class="tiles">
  <?php foreach ($mods as $k => $m): ?>
    <a class="tile" href="<?= h($m['entry']) ?>"><b><?= h($m['name']) ?></b><span><?= h($m['about']) ?></span>
      <?php if ($m['status'] !== 'built'): ?><em><?= $m['status'] === 'port' ? 'Porting' : 'Planned' ?></em><?php endif; ?></a>
  <?php endforeach; ?>
  </div></section>
<?php endforeach; ?>
<?php ui_foot();
