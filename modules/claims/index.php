<?php
// Expense claims — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('claims');
ui_head('Expense claims', $u, 'claims');
?>
<section class="card">
  <h2>Expense claims</h2>
  <p><?= h(module_catalog()['claims']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/claims/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
