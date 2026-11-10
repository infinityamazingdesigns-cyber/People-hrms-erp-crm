<?php
// Biometric machines — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('biometric');
ui_head('Biometric machines', $u, 'biometric');
?>
<section class="card">
  <h2>Biometric machines</h2>
  <p><?= h(module_catalog()['biometric']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/biometric/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
