<?php
// Lite mode — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('lite');
ui_head('Lite mode', $u, 'lite');
?>
<section class="card">
  <h2>Lite mode</h2>
  <p><?= h(module_catalog()['lite']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/lite/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
