<?php
// Group insurance — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('insurance');
ui_head('Group insurance', $u, 'insurance');
?>
<section class="card">
  <h2>Group insurance</h2>
  <p><?= h(module_catalog()['insurance']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/insurance/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
