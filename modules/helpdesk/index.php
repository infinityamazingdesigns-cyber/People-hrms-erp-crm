<?php
// Help desk — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('helpdesk');
ui_head('Help desk', $u, 'helpdesk');
?>
<section class="card">
  <h2>Help desk</h2>
  <p><?= h(module_catalog()['helpdesk']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/helpdesk/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
