<?php
// Full & final — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('fnf');
ui_head('Full & final', $u, 'fnf');
?>
<section class="card">
  <h2>Full & final</h2>
  <p><?= h(module_catalog()['fnf']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/fnf/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
