<?php
// CRM & orders — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('crm');
ui_head('CRM & orders', $u, 'crm');
?>
<section class="card">
  <h2>CRM & orders</h2>
  <p><?= h(module_catalog()['crm']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/crm/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
