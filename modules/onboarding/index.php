<?php
// Onboarding — joiners with their checklist, convert to the employee master, and HR's checklist items.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/onboarding.php';
$u = module_require('onboarding');
$db = pdo();
$can = onb_can($u);
$id = (int)($_GET['j'] ?? $_POST['j'] ?? 0);
$view = ($_GET['view'] ?? '') === 'items' ? 'items' : 'joiners';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can) {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $to = $id ? 'index.php?j=' . $id : 'index.php';
    try {
        switch ($a) {
            case 'add': $id = onb_add($db, $u, $_POST, !empty($_POST['joined'])); $to = 'index.php?j=' . $id; flash('Joiner added.'); break;
            case 'edit': onb_edit($db, $u, $id, $_POST); flash('Saved.'); break;
            case 'tick': onb_tick($db, $u, $id, (int)$_POST['item'], !empty($_POST['on'])); break;
            case 'convert': $eid = onb_convert($db, $u, $id); flash('On the employee master now (#' . $eid . '). Probation follows on the Probation page.'); break;
            case 'off': onb_off($db, $u, $id, (string)($_POST['reason'] ?? '')); flash('Switched off.'); $to = 'index.php'; break;
            case 'itemadd': onb_item_add($db, $u, (string)$_POST['phase'], (string)$_POST['text'], (string)($_POST['owner'] ?? '')); flash('Item added.'); $to = 'index.php?view=items'; break;
            case 'itemoff': case 'itemon': onb_item_switch($db, $u, (int)$_POST['item'], $a === 'itemon', (string)($_POST['reason'] ?? '')); flash('Saved.'); $to = 'index.php?view=items'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

$brName = array_column(branches(false), 'name', 'id');
$items = onb_items($db);
ui_head('Onboarding', $u, 'onboarding');
$fields = function (?array $j) use ($u) { $v = fn($k) => h((string)($j[$k] ?? '')); ?>
  <div class="grid">
    <label>Name <input name="name" value="<?= $v('name') ?>" required maxlength="120"></label>
    <label>Employee code <input name="emp_code" value="<?= $v('emp_code') ?>" maxlength="32" autocomplete="off"<?= !empty($j['employee_id']) ? ' disabled' : '' ?>></label>
    <label>Joining date <input type="date" name="join_date" value="<?= $v('join_date') ?>"></label>
    <label>Branch <select name="branch_id"><option value="">—</option><?php foreach (branches() as $b): if (!emp_in_scope($u, ['branch_id' => $b['id']])) continue; ?><option value="<?= (int)$b['id'] ?>"<?= (int)($j['branch_id'] ?? 0) === (int)$b['id'] ? ' selected' : '' ?>><?= h(branch_label($b['name'])) ?></option><?php endforeach; ?></select></label>
    <label>Department <input name="department" value="<?= $v('department') ?>" maxlength="80"></label>
    <label>Designation <input name="designation" value="<?= $v('designation') ?>" maxlength="80"></label>
    <label>Reports to (code) <input name="manager_code" value="<?= $v('manager_code') ?>" maxlength="32"></label>
    <label>Email <input type="email" name="email" value="<?= $v('email') ?>"></label>
    <label>Phone <input name="phone" value="<?= $v('phone') ?>"></label>
  </div>
  <label>Note <input name="note" value="<?= $v('note') ?>" maxlength="500"></label>
<?php };
?>
<nav class="row"><a class="btn sm<?= $view === 'joiners' && !$id ? '' : ' ghost' ?>" href="index.php">Joiners</a><?php if ($can): ?><a class="btn sm<?= $view === 'items' ? '' : ' ghost' ?>" href="?view=items">Checklist items</a><?php endif; ?></nav>

<?php if ($id && ($j = onb_get($db, $id)) && (!$j['branch_id'] || emp_in_scope($u, $j))):
    $ticks = onb_ticks($db, $id); $p = onb_progress($items, $ticks); ?>
<p><a href="index.php">‹ All joiners</a></p>
<section class="card"><h2><?= h($j['name']) ?> <span class="pill <?= $j['employee_id'] ? 'on' : 'port' ?>"><?= $j['employee_id'] ? 'On the employee master' : 'Joining' ?></span><?= $j['active'] ? '' : ' <span class="pill off">Off</span>' ?></h2>
  <p><b><?= $p['pct'] ?>%</b> done (<?= $p['done'] ?> of <?= $p['of'] ?>)<?= $j['join_date'] ? ' · joins ' . h(date('j M Y', strtotime($j['join_date']))) : '' ?><?= $j['branch_id'] ? ' · ' . h(branch_label((string)($brName[$j['branch_id']] ?? ''))) : '' ?></p>
  <?php if ($j['active'] && $can && !$j['employee_id']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="j" value="<?= $id ?>"><button class="btn sm" name="a" value="convert">Convert to employee</button>
    <small class="muted">Needs the code and joining date. Makes the employee master record.</small></form><?php endif; ?>
</section>
<?php foreach (ONB_PHASES as $ph => $title): $list = array_filter($items, fn($i) => $i['phase'] === $ph && ($i['active'] || isset($ticks[(int)$i['id']]))); if (!$list) continue; ?>
<section class="card"><h2><?= h($title) ?> <small class="muted"><?= ($p['phases'][$ph][0] ?? 0) . ' / ' . ($p['phases'][$ph][1] ?? 0) ?></small></h2>
  <?php foreach ($list as $i): $t = $ticks[(int)$i['id']] ?? null; ?>
  <form method="post" class="row" style="margin:4px 0"><?= csrf_field() ?><input type="hidden" name="j" value="<?= $id ?>"><input type="hidden" name="a" value="tick"><input type="hidden" name="item" value="<?= (int)$i['id'] ?>"><input type="hidden" name="on" value="<?= $t ? '' : '1' ?>">
    <button class="btn sm <?= $t ? '' : 'ghost' ?>"<?= $can && $j['active'] ? '' : ' disabled' ?> aria-label="<?= $t ? 'Untick' : 'Tick' ?>"><?= $t ? '✓' : '○' ?></button>
    <span><?= h($i['text']) ?><?= $i['owner'] !== '' ? ' <small class="muted">· ' . h($i['owner']) . '</small>' : '' ?><?= $t ? ' <small class="muted">— ' . h($t['by'] . ', ' . date('j M', strtotime($t['at']))) . '</small>' : '' ?></span></form>
  <?php endforeach; ?></section>
<?php endforeach; ?>
<?php if ($can && $j['active']): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="j" value="<?= $id ?>"><input type="hidden" name="a" value="edit"><h2>Details</h2><?php $fields($j); ?><button class="btn sm">Save</button></form>
<form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="j" value="<?= $id ?>"><input type="hidden" name="a" value="off"><input name="reason" placeholder="Reason (not joining, duplicate …)" required><button class="btn sm ghost">Switch off this joiner</button></form>
<?php endif; ?>

<?php elseif ($view === 'items' && $can): ?>
<?php foreach (ONB_PHASES as $ph => $title): ?><section class="card"><h2><?= h($title) ?></h2><table><tbody>
  <?php foreach (array_filter($items, fn($i) => $i['phase'] === $ph) as $i): ?><tr><td><?= h($i['text']) ?></td><td><small class="muted"><?= h($i['owner']) ?></small></td>
    <td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="item" value="<?= (int)$i['id'] ?>"><?php if ($i['active']): ?><input name="reason" placeholder="Reason" required><button class="btn sm ghost" name="a" value="itemoff">Switch off</button><?php else: ?><span class="pill off">Off</span> <small><?= h($i['off_reason']) ?></small> <button class="btn sm ghost" name="a" value="itemon">Switch on</button><?php endif; ?></form></td></tr>
  <?php endforeach; ?></tbody></table></section><?php endforeach; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="itemadd"><h2>Add an item</h2>
  <div class="grid"><label>Phase <select name="phase"><?php foreach (ONB_PHASES as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>Item <input name="text" maxlength="160" required></label><label>Who <input name="owner" maxlength="60" placeholder="HR / Manager / IT"></label></div>
  <p class="muted">Switched-off items stay on checklists where they were ticked.</p><button class="btn sm">Add</button></form>

<?php else:
    $all = onb_list($db, $u); ?>
<section class="card"><h2>Joiners · <?= count($all) ?></h2>
  <table><thead><tr><th>Name</th><th>Code</th><th>Joins</th><th>Branch</th><th>Done</th><th></th></tr></thead><tbody>
  <?php foreach ($all as $j): $p = onb_progress($items, onb_ticks($db, (int)$j['id'])); ?><tr><td><?= h($j['name']) ?></td><td><?= h($j['emp_code']) ?></td><td><?= $j['join_date'] ? h(date('j M Y', strtotime($j['join_date']))) : '' ?></td>
    <td><?= h(branch_label((string)($brName[$j['branch_id']] ?? ''))) ?></td><td><?= $p['pct'] ?>%<?= $j['employee_id'] ? ' <span class="pill on">employee</span>' : '' ?></td><td><a href="?j=<?= (int)$j['id'] ?>">Open</a></td></tr>
  <?php endforeach; ?><?= $all ? '' : '<tr><td colspan="6" class="muted">No joiners yet.</td></tr>' ?></tbody></table></section>
<?php if ($can): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="add"><h2>Add a joiner</h2><?php $fields(null); ?>
  <p><label class="tick"><input type="checkbox" name="joined" value="1"> Already joined — tick the before-joining and joining-day items</label></p><button class="btn sm">Add</button></form><?php endif; ?>
<?php endif;
ui_foot();
