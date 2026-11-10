<?php
// Careers page — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('careers');
ui_head('Careers page', $u, 'careers');
?>
<section class="card">
  <h2>Careers page</h2>
  <p><?= h(module_catalog()['careers']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/careers/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
