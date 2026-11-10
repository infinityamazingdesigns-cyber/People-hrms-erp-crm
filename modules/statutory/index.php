<?php
// Statutory compliance — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('statutory');
ui_head('Statutory compliance', $u, 'statutory');
?>
<section class="card">
  <h2>Statutory compliance</h2>
  <p><?= h(module_catalog()['statutory']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/statutory/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
