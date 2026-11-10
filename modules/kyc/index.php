<?php
// KYC & bank — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('kyc');
ui_head('KYC & bank', $u, 'kyc');
?>
<section class="card">
  <h2>KYC & bank</h2>
  <p><?= h(module_catalog()['kyc']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/kyc/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
