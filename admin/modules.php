<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$u = module_require('modules');
$db = pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $key = (string)($_POST['key'] ?? '');
    if ($a === 'switch') {
        [$changed, $e] = module_switch($u, $key, ($_POST['on'] ?? '') === '1');
        flash($e ?? ('Switched ' . (($_POST['on'] ?? '') === '1' ? 'on: ' : 'off: ') . implode(', ', array_map(fn($k) => module_catalog()[$k]['name'], $changed))), $e ? 'err' : 'ok');
    } elseif ($a === 'roles') {
        $e = module_roles_save($u, $key, (array)($_POST['roles'] ?? []));
        flash($e ?? 'Access saved for ' . (module_catalog()[$key]['name'] ?? $key) . '.', $e ? 'err' : 'ok');
    } elseif ($a === 'person') {
        $mode = in_array($_POST['mode'] ?? '', ['allow', 'deny'], true) ? $_POST['mode'] : null;
        $e = module_person_save($u, (int)($_POST['uid'] ?? 0), $key, $mode, (string)($_POST['reason'] ?? ''));
        flash($e ?? 'Saved.', $e ? 'err' : 'ok');
    }
    redirect('modules.php' . ($key !== '' ? '#m-' . rawurlencode($key) : ''));
}

$roles = role_labels();
$people = $db->query('SELECT id, name, username FROM users WHERE active = 1 ORDER BY name')->fetchAll();
$over = $db->query('SELECT mp.*, us.name FROM module_person mp JOIN users us ON us.id = mp.user_id ORDER BY us.name')->fetchAll();
$on = count(array_filter(array_keys(module_catalog()), 'module_on'));

ui_head('Modules', $u, 'modules');
?>
<section class="card"><p><b><?= $on ?></b> of <?= count(module_catalog()) ?> modules are on. Switching a module on also switches on what it needs.
Tick which roles may use each module; per-person exceptions are at the foot of the page.</p></section>
<?php foreach (MODULE_GROUPS as $g => $glabel): ?>
<section class="card"><h2><?= h($glabel) ?></h2>
<?php foreach (module_catalog() as $k => $m): if ($m['group'] !== $g) continue; $isOn = module_on($k); $mr = module_roles($k); ?>
  <div class="mod<?= $isOn ? ' is-on' : '' ?>" id="m-<?= h($k) ?>">
    <div class="mod-h">
      <div><b><?= h($m['name']) ?></b> <span class="pill <?= h($m['status']) ?>"><?= ['built' => 'Ready', 'port' => 'Porting', 'planned' => 'Planned'][$m['status']] ?></span>
        <?php if (!empty($m['needs'])): ?><small>needs <?= h(implode(', ', array_map(fn($n) => module_catalog()[$n]['name'], $m['needs']))) ?></small><?php endif; ?>
        <p><?= h($m['about']) ?></p></div>
      <?php if (!empty($m['core'])): ?><span class="pill on">Always on</span>
      <?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="switch"><input type="hidden" name="key" value="<?= h($k) ?>"><input type="hidden" name="on" value="<?= $isOn ? '0' : '1' ?>">
        <button class="btn sm<?= $isOn ? ' ghost' : '' ?>"><?= $isOn ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?>
    </div>
    <form method="post" class="roles"><?= csrf_field() ?><input type="hidden" name="a" value="roles"><input type="hidden" name="key" value="<?= h($k) ?>">
      <?php foreach ($roles as $rc => $rl): ?><label class="tick"><input type="checkbox" name="roles[]" value="<?= h($rc) ?>"<?= in_array($rc, $mr, true) ? ' checked' : '' ?><?= !empty($m['core']) && $rc === 'SUPERADMIN' ? ' checked disabled' : '' ?>><?= h($rl) ?></label><?php endforeach; ?>
      <button class="btn sm ghost">Save access</button></form>
  </div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>

<section class="card"><h2>Per-person exceptions</h2>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="person">
  <select name="uid" required><option value="">Person…</option><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name'] . ' (' . $p['username'] . ')') ?></option><?php endforeach; ?></select>
  <select name="key" required><option value="">Module…</option><?php foreach (module_catalog() as $k => $m): ?><option value="<?= h($k) ?>"><?= h($m['name']) ?></option><?php endforeach; ?></select>
  <select name="mode"><option value="allow">Allow</option><option value="deny">Deny</option><option value="">As their role (remove exception)</option></select>
  <input name="reason" placeholder="Reason" maxlength="300">
  <button class="btn sm">Save</button></form>
<table><thead><tr><th>Person</th><th>Module</th><th>Exception</th><th>Reason</th><th>Set</th></tr></thead><tbody>
<?php foreach ($over as $o): ?><tr><td><?= h($o['name']) ?></td><td><?= h(module_catalog()[$o['module_key']]['name'] ?? $o['module_key']) ?></td><td><span class="pill <?= $o['mode'] === 'allow' ? 'on' : 'off' ?>"><?= h(ucfirst($o['mode'])) ?></span></td><td><?= h($o['reason']) ?></td><td><?= h($o['set_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$over): ?><tr><td colspan="5"><em>No exceptions.</em></td></tr><?php endif; ?>
</tbody></table></section>
<?php ui_foot();
