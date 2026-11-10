<?php
// Payslips — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('payslips');
ui_head('Payslips', $u, 'payslips');
?>
<section class="card">
  <h2>Payslips</h2>
  <p><?= h(module_catalog()['payslips']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/payslips/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
