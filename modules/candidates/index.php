<?php
// Candidate portal — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('candidates');
ui_head('Candidate portal', $u, 'candidates');
?>
<section class="card">
  <h2>Candidate portal</h2>
  <p><?= h(module_catalog()['candidates']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/candidates/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
