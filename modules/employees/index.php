<?php
// Employee master — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('employees');
ui_head('Employee master', $u, 'employees');
?>
<section class="card">
  <h2>Employee master</h2>
  <p><?= h(module_catalog()['employees']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/employees/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
