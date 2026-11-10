<?php
// Shifts & weekly offs — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('shifts');
ui_head('Shifts & weekly offs', $u, 'shifts');
?>
<section class="card">
  <h2>Shifts & weekly offs</h2>
  <p><?= h(module_catalog()['shifts']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/shifts/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
