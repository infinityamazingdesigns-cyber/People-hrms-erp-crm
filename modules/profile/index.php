<?php
// My profile — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('profile');
ui_head('My profile', $u, 'profile');
?>
<section class="card">
  <h2>My profile</h2>
  <p><?= h(module_catalog()['profile']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/profile/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
