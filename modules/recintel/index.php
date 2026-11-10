<?php
// Recruitment intelligence — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('recintel');
ui_head('Recruitment intelligence', $u, 'recintel');
?>
<section class="card">
  <h2>Recruitment intelligence</h2>
  <p><?= h(module_catalog()['recintel']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/recintel/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
