<?php
// Feedback & conduct — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('conduct');
ui_head('Feedback & conduct', $u, 'conduct');
?>
<section class="card">
  <h2>Feedback & conduct</h2>
  <p><?= h(module_catalog()['conduct']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/conduct/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
