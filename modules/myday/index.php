<?php
// My day — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('myday');
ui_head('My day', $u, 'myday');
?>
<section class="card">
  <h2>My day</h2>
  <p><?= h(module_catalog()['myday']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/myday/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
