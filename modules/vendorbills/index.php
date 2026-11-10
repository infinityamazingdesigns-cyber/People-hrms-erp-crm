<?php
// Vendor bills (HO) — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('vendorbills');
ui_head('Vendor bills (HO)', $u, 'vendorbills');
?>
<section class="card">
  <h2>Vendor bills (HO)</h2>
  <p><?= h(module_catalog()['vendorbills']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/vendorbills/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
