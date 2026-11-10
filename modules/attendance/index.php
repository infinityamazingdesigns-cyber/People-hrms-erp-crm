<?php
// Attendance — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$u = module_require('attendance');
ui_head('Attendance', $u, 'attendance');
?>
<section class="card">
  <h2>Attendance</h2>
  <p><?= h(module_catalog()['attendance']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/attendance/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();
