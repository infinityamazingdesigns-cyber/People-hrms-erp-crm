<?php
// Letter desk — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('letters');
ui_head('Letter desk', $u, 'letters');
?>
<section class="card">
  <h2>Letter desk</h2>
  <p><?= h(module_catalog()['letters']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/letters/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
