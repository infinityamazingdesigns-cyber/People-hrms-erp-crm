<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$u = require_login();
migrate(pdo());   // new migrations land on the first page after a deploy

ui_head('Home', $u);
$menu = module_menu($u);
?>
<section class="hello card">
  <h2>Hello, <?= h(explode(' ', $u['name'])[0]) ?></h2>
  <p><?= h(date('l, j F Y')) ?></p>
  <?php if (is_superadmin($u) && org('setup_done') !== '1'): ?>
    <p class="note">Setup is not finished. <a href="admin/guide.php">Open the setup checklist</a>.</p>
  <?php endif; ?>
</section>
<?php foreach ($menu as $g => $mods): ?>
  <section class="band"><h3><?= h(MODULE_GROUPS[$g] ?? $g) ?></h3><div class="tiles">
  <?php foreach ($mods as $k => $m): ?>
    <a class="tile" href="<?= h($m['entry']) ?>"><b><?= h($m['name']) ?></b><span><?= h($m['about']) ?></span>
      <?php if ($m['status'] !== 'built'): ?><em><?= $m['status'] === 'port' ? 'Porting' : 'Planned' ?></em><?php endif; ?></a>
  <?php endforeach; ?>
  </div></section>
<?php endforeach; ?>
<?php ui_foot();
