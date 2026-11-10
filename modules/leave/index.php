<?php
// Leave — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('leave');
ui_head('Leave', $u, 'leave');
?>
<section class="card">
  <h2>Leave</h2>
  <p><?= h(module_catalog()['leave']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/leave/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
