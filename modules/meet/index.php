<?php
// Video meetings — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('meet');
ui_head('Video meetings', $u, 'meet');
?>
<section class="card">
  <h2>Video meetings</h2>
  <p><?= h(module_catalog()['meet']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/meet/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
