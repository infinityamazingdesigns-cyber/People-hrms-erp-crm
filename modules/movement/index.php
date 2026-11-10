<?php
// Staff movement — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('movement');
ui_head('Staff movement', $u, 'movement');
?>
<section class="card">
  <h2>Staff movement</h2>
  <p><?= h(module_catalog()['movement']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/movement/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
