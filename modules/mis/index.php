<?php
// HR dashboard (MIS) — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('mis');
ui_head('HR dashboard (MIS)', $u, 'mis');
?>
<section class="card">
  <h2>HR dashboard (MIS)</h2>
  <p><?= h(module_catalog()['mis']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/mis/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
