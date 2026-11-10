<?php
// Browser notifications — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('notify');
ui_head('Browser notifications', $u, 'notify');
?>
<section class="card">
  <h2>Browser notifications</h2>
  <p><?= h(module_catalog()['notify']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/notify/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
