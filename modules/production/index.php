<?php
// Production workers — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('production');
ui_head('Production workers', $u, 'production');
?>
<section class="card">
  <h2>Production workers</h2>
  <p><?= h(module_catalog()['production']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/production/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
