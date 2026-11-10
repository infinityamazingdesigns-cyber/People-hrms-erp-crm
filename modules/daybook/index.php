<?php
// Branch day book — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('daybook');
ui_head('Branch day book', $u, 'daybook');
?>
<section class="card">
  <h2>Branch day book</h2>
  <p><?= h(module_catalog()['daybook']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/daybook/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
