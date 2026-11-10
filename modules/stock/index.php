<?php
// Stock & inventory — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('stock');
ui_head('Stock & inventory', $u, 'stock');
?>
<section class="card">
  <h2>Stock & inventory</h2>
  <p><?= h(module_catalog()['stock']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/stock/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
