<?php
// Marketing & ROAS — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('marketing');
ui_head('Marketing & ROAS', $u, 'marketing');
?>
<section class="card">
  <h2>Marketing & ROAS</h2>
  <p><?= h(module_catalog()['marketing']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/marketing/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
