<?php
// Team chat — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('talk');
ui_head('Team chat', $u, 'talk');
?>
<section class="card">
  <h2>Team chat</h2>
  <p><?= h(module_catalog()['talk']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/talk/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
