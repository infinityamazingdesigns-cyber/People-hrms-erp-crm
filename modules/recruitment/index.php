<?php
// Recruitment pipeline — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('recruitment');
ui_head('Recruitment pipeline', $u, 'recruitment');
?>
<section class="card">
  <h2>Recruitment pipeline</h2>
  <p><?= h(module_catalog()['recruitment']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/recruitment/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
