<?php
// Org chart — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('orgchart');
ui_head('Org chart', $u, 'orgchart');
?>
<section class="card">
  <h2>Org chart</h2>
  <p><?= h(module_catalog()['orgchart']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/orgchart/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
