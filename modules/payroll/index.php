<?php
// Payroll — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('payroll');
ui_head('Payroll', $u, 'payroll');
?>
<section class="card">
  <h2>Payroll</h2>
  <p><?= h(module_catalog()['payroll']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/payroll/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
