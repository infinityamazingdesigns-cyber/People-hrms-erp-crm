<?php
/** Project guide inside the product: setup checklist (each line closes by itself) + module catalog. */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$u = module_require('guide');
$db = pdo();

$count = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
$steps = [
    ['Organisation profile filled (name, legal name, country, time zone)', org('legal_name') !== '' && org('name') !== ORG_DEFAULTS['name'], 'organization.php'],
    ['Logo and colours set', org('logo') !== '', 'organization.php'],
    ['At least one branch with its shift and weekly off', $count('SELECT COUNT(*) FROM branch WHERE active = 1') > 0, 'organization.php'],
    ['Departments and designations listed', $count('SELECT COUNT(*) FROM department') > 0 && $count('SELECT COUNT(*) FROM designation') > 0, 'organization.php'],
    ['Modules chosen for this organisation', $count('SELECT COUNT(*) FROM module_state WHERE enabled = 1') > 0, 'modules.php'],
    ['HR Manager login created', $count("SELECT COUNT(*) FROM user_role WHERE role_code = 'HRMANAGER'") > 0, 'users.php'],
    ['A second Super Admin or CEO for approvals', $count("SELECT COUNT(DISTINCT user_id) FROM user_role WHERE role_code IN ('SUPERADMIN','CEO')") > 1, 'users.php'],
];
$done = count(array_filter($steps, fn($s) => $s[1]));
if ($done === count($steps) && org('setup_done') !== '1') org_set('setup_done', '1', (int)$u['id']);

ui_head('Project guide', $u, 'guide');
?>
<section class="card"><h2>Setup checklist · <?= $done ?> of <?= count($steps) ?></h2>
<ol class="check"><?php foreach ($steps as [$label, $ok, $href]): ?>
  <li class="<?= $ok ? 'ok' : '' ?>"><?= $ok ? '✓' : '○' ?> <?= h($label) ?> <?php if (!$ok): ?><a href="<?= h($href) ?>">Open</a><?php endif; ?></li>
<?php endforeach; ?></ol></section>

<section class="card"><h2>How access works</h2>
<ol>
  <li><b>Owner</b> — the person who installed the product. Always Super Admin; nobody can switch the login off or take the role away.</li>
  <li><b>Modules</b> — the Super Admin switches modules on for the organisation and ticks which roles may use each.</li>
  <li><b>Roles</b> — each login holds one or more roles (Users & access). Employee-only logins see only their own records.</li>
  <li><b>Per-person exceptions</b> — allow one module to someone whose roles do not open it, or deny one they do.</li>
  <li><b>Branches</b> — a login tied to branches sees only those branches; none ticked = every branch.</li>
</ol></section>

<section class="card"><h2>Modules</h2>
<?php foreach (MODULE_GROUPS as $g => $gl): ?><h3><?= h($gl) ?></h3><table><tbody>
<?php foreach (module_catalog() as $k => $m): if ($m['group'] !== $g) continue; ?>
  <tr><td><b><?= h($m['name']) ?></b></td><td><?= h($m['about']) ?></td>
  <td><span class="pill <?= h($m['status']) ?>"><?= ['built' => 'Ready', 'port' => 'Porting', 'planned' => 'Planned'][$m['status']] ?></span></td>
  <td><?= module_on($k) ? '<span class="pill on">On</span>' : '<span class="pill off">Off</span>' ?></td></tr>
<?php endforeach; ?></tbody></table><?php endforeach; ?>
<p class="muted">Full guide: <code>docs/PROJECT_GUIDE.md</code> in the repository.</p></section>
<?php ui_foot();
