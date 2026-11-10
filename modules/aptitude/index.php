<?php
// Aptitude test — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('aptitude');
ui_head('Aptitude test', $u, 'aptitude');
?>
<section class="card">
  <h2>Aptitude test</h2>
  <p><?= h(module_catalog()['aptitude']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/aptitude/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
