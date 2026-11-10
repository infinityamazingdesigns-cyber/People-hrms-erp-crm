<?php
// Tasks — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('tasks');
ui_head('Tasks', $u, 'tasks');
?>
<section class="card">
  <h2>Tasks</h2>
  <p><?= h(module_catalog()['tasks']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/tasks/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
