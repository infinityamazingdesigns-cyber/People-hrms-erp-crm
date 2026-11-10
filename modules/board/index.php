<?php
// Announcement board — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('board');
ui_head('Announcement board', $u, 'board');
?>
<section class="card">
  <h2>Announcement board</h2>
  <p><?= h(module_catalog()['board']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/board/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
