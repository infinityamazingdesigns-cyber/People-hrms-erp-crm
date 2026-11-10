<?php
// Asset register — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('assets');
ui_head('Asset register', $u, 'assets');
?>
<section class="card">
  <h2>Asset register</h2>
  <p><?= h(module_catalog()['assets']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/assets/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
