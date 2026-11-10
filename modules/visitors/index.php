<?php
// Visitors — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('visitors');
ui_head('Visitors', $u, 'visitors');
?>
<section class="card">
  <h2>Visitors</h2>
  <p><?= h(module_catalog()['visitors']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/visitors/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
