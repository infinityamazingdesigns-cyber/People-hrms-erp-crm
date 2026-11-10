<?php
// Holiday calendar — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('holidays');
ui_head('Holiday calendar', $u, 'holidays');
?>
<section class="card">
  <h2>Holiday calendar</h2>
  <p><?= h(module_catalog()['holidays']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/holidays/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
