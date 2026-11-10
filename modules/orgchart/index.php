<?php
// Org chart — my reporting line, the whole chart with search, and (for HR) moves, open positions and checks.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/orgchart.php';
$u = module_require('orgchart');
$db = pdo();
$today = date('Y-m-d');
$holder = org_holder($u);
$canEdit = $holder && emp_can_edit($u);
$brName = array_column(branches(false), 'name', 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $e = match ($a) {
        'move' => org_move($db, $u, (string)($_POST['code'] ?? ''), (string)($_POST['manager'] ?? '')),
        'pos_add' => org_position_add($db, $u, $_POST),
        'pos_close' => org_position_close($db, $u, (int)($_POST['id'] ?? 0), (string)($_POST['filled'] ?? ''), (string)($_POST['note'] ?? '')),
        default => 'Unknown action.',
    };
    flash($e ?? 'Saved.', $e ? 'err' : 'ok');
    redirect('index.php?v=' . ($a === 'move' ? 'all' : 'pos'));
}

$people = $db->query('SELECT * FROM employee')->fetchAll();
$positions = org_positions($db);
$showLeft = $holder && isset($_GET['left']);
$t = org_build($people, $positions, $holder, $today, $showLeft);
$N = $t['nodes'];
$me = strtoupper((string)($u['emp_code'] ?? ''));
$v = in_array($_GET['v'] ?? '', ['mine', 'all', 'pos', 'checks'], true) ? $_GET['v'] : (isset($N[$me]) ? 'mine' : 'all');
if (!$holder && in_array($v, ['pos', 'checks'], true)) $v = 'all';

function oc_card(array $n, array $brName, string $extra = ''): string {
    $st = $n['status'] ?? '';
    $badge = match ($st) { 'notice' => '<span class="pill port">On notice</span>', 'left' => '<span class="pill off">Left</span>', 'open' => '<span class="pill planned">Open</span>', default => '' };
    return '<div class="oc-card' . ($st === 'open' ? ' oc-open' : '') . '"><b>' . h($n['name']) . '</b> ' . $badge . '<span>' . h(trim($n['designation'] . ($n['department'] ? ' · ' . $n['department'] : ''), ' ·'))
        . ($n['branch_id'] && isset($brName[$n['branch_id']]) ? ' · ' . h($brName[$n['branch_id']]) : '') . '</span>' . $extra . '</div>';
}
function oc_tree(string $c, array $t, array $brName, int $depth = 0): string {
    $kids = $t['kids'][$c] ?? [];
    $n = $t['nodes'][$c];
    $txt = strtolower($n['name'] . ' ' . $n['designation'] . ' ' . $n['department']);
    $card = oc_card($n, $brName, $kids ? '<small>' . count(org_team($c, $t['parent'])) . ' in team</small>' : '');
    if (!$kids) return '<li data-q="' . h($txt) . '">' . $card . '</li>';
    $inner = implode('', array_map(fn($k) => oc_tree($k, $t, $brName, $depth + 1), $kids));
    return '<li data-q="' . h($txt) . '"><details' . ($depth < 2 ? ' open' : '') . '><summary>' . $card . '</summary><ul>' . $inner . '</ul></details></li>';
}

ui_head('Org chart', $u, 'orgchart');
$tabs = ['mine' => 'My reporting line', 'all' => 'Whole chart'] + ($holder ? ['pos' => 'Open positions', 'checks' => 'Checks'] : []);
?>
<style>
.oc-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}.oc-tabs a{padding:6px 14px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:var(--ink);font-size:14px}
.oc-tabs a.on{background:var(--brand);color:#fff;border-color:var(--brand)}
.oc ul{list-style:none;margin:0;padding-left:22px;border-left:1px dashed var(--line)}.oc>ul{padding-left:0;border:0}
.oc li{margin:6px 0}.oc summary{list-style:none;cursor:pointer}.oc summary::-webkit-details-marker{display:none}
.oc-card{display:inline-flex;flex-direction:column;gap:2px;background:var(--card);border:1px solid var(--line);border-left:3px solid var(--brand);border-radius:8px;padding:8px 12px;min-width:220px}
.oc-card span,.oc-card small{font-size:12px;color:var(--mu)}.oc-open{border-style:dashed;border-left-color:var(--accent)}
.oc-me{border-left-color:var(--accent);box-shadow:0 0 0 2px var(--accent) inset}
.line{display:flex;flex-direction:column;gap:6px;align-items:flex-start}.line .arrow{color:var(--mu);padding-left:20px}
</style>
<nav class="oc-tabs"><?php foreach ($tabs as $k => $l): ?><a href="?v=<?= $k ?>" class="<?= $v === $k ? 'on' : '' ?>"><?= h($l) ?></a><?php endforeach; ?></nav>

<?php if ($v === 'mine'): ?>
  <?php if (!isset($N[$me])): ?><section class="card"><p>Your login is not linked to an employee code yet, so your line cannot be drawn. Ask HR to link it on Users & access.</p></section>
  <?php else: $chain = org_chain($me, $t['parent']); $mgr = $t['parent'][$me] ?? ''; ?>
  <div class="grid2">
  <section class="card"><h2>Your line to the top</h2><div class="line">
    <?php foreach ($chain as $i => $c): ?><?= $i ? '<span class="arrow">↓</span>' : '' ?><?= str_replace('oc-card', 'oc-card' . ($c === $me ? ' oc-me' : ''), oc_card($N[$c], $brName)) ?><?php endforeach; ?>
  </div></section>
  <section class="card"><h2>Reports to you</h2>
    <?php $mine = $t['kids'][$me] ?? []; if (!$mine): ?><p class="muted">Nobody reports to you.</p><?php else: ?><div class="line"><?php foreach ($mine as $c) echo oc_card($N[$c], $brName); ?></div><?php endif; ?>
    <?php if ($mgr !== ''): $peers = array_values(array_diff($t['kids'][$mgr] ?? [], [$me])); ?>
      <h2 style="margin-top:16px">Same manager as you</h2>
      <?php if (!$peers): ?><p class="muted">Nobody else.</p><?php else: ?><div class="line"><?php foreach ($peers as $c) echo oc_card($N[$c], $brName); ?></div><?php endif; ?>
    <?php endif; ?>
  </section></div>
  <?php endif; ?>

<?php elseif ($v === 'all'): ?>
<section class="card">
  <div class="row"><input id="ocq" placeholder="Find a person, designation or department" style="flex:1">
    <?php if ($holder): ?><a class="btn sm ghost" href="?v=all<?= $showLeft ? '' : '&left=1' ?>"><?= $showLeft ? 'Hide leavers' : 'Show leavers' ?></a><?php endif; ?>
    <button class="btn sm ghost" type="button" onclick="document.querySelectorAll('.oc details').forEach(d=>d.open=true)">Open all</button></div>
  <div class="oc"><ul><?php foreach ($t['roots'] as $r) echo oc_tree($r, $t, $brName); ?></ul></div>
  <?php if (!$N): ?><p class="muted">No one on the employee master yet.</p><?php endif; ?>
</section>
<?php if ($canEdit): $roll = emp_on_roll($db, $u, $today); ?>
<form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="move">
  <b>Move someone</b>
  <select name="code" required><option value="">Person…</option><?php foreach ($roll as $p): ?><option value="<?= h($p['code']) ?>"><?= h($p['name'] . ' · ' . $p['code']) ?></option><?php endforeach; ?></select>
  <span>now reports to</span>
  <select name="manager"><option value="">Nobody (top of the chart)</option><?php foreach ($roll as $p): ?><option value="<?= h($p['code']) ?>"><?= h($p['name'] . ' · ' . $p['code']) ?></option><?php endforeach; ?></select>
  <button class="btn sm">Move</button></form>
<?php endif; ?>
<script>
document.getElementById('ocq').addEventListener('input', e => {
  const q = e.target.value.trim().toLowerCase();
  document.querySelectorAll('.oc li').forEach(li => { li.style.display = ''; });
  if (!q) return;
  document.querySelectorAll('.oc li').forEach(li => {
    const hit = [...li.querySelectorAll('li')].concat(li).some(x => (x.dataset.q || '').includes(q));
    li.style.display = hit ? '' : 'none';
    if (hit) li.querySelectorAll('details').forEach(d => d.open = true);
  });
});
</script>

<?php elseif ($v === 'pos'): $all = org_positions($db, 'all'); $roll = emp_on_roll($db, $u, $today); $leftPeople = array_filter($people, fn($p) => $p['active'] && emp_status($p, $today) !== 'active'); ?>
<?php if ($canEdit): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="pos_add"><h2>Open a position</h2><div class="grid">
  <label>Title <input name="title" required maxlength="80"></label>
  <label>Department <input name="department" maxlength="80"></label>
  <label>Branch <select name="branch_id"><option value="">—</option><?php foreach ($brName as $id => $n): ?><option value="<?= (int)$id ?>"><?= h($n) ?></option><?php endforeach; ?></select></label>
  <label>Reports to <select name="manager_code"><option value="">—</option><?php foreach ($roll as $p): ?><option value="<?= h($p['code']) ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></label>
  <label>Replaces (leaver) <select name="replaces_code"><option value="">— new seat —</option><?php foreach ($leftPeople as $p): ?><option value="<?= h($p['code']) ?>"><?= h($p['name'] . ' · ' . emp_status_label($p, $today)) ?></option><?php endforeach; ?></select></label>
  <label>Note <input name="note" maxlength="300"></label></div><button class="btn sm">Open position</button></form>
<?php endif; ?>
<section class="card"><h2>Positions</h2><table><thead><tr><th>Title</th><th>Department</th><th>Branch</th><th>Reports to</th><th>Replaces</th><th>Status</th><th></th></tr></thead><tbody>
<?php $names = array_column($people, 'name', 'code'); foreach (array_reverse($all) as $p): ?>
<tr><td><?= h($p['title']) ?></td><td><?= h($p['department']) ?></td><td><?= h($brName[$p['branch_id']] ?? '') ?></td><td><?= h($names[$p['manager_code']] ?? $p['manager_code']) ?></td><td><?= h($names[$p['replaces_code']] ?? $p['replaces_code']) ?></td>
<td><span class="pill <?= $p['status'] === 'open' ? 'planned' : ($p['status'] === 'filled' ? 'on' : 'off') ?>"><?= h(ucfirst($p['status'])) ?></span><?= $p['filled_code'] ? ' ' . h($names[$p['filled_code']] ?? $p['filled_code']) : '' ?> <small class="muted"><?= h($p['note']) ?></small></td>
<td><?php if ($canEdit && $p['status'] === 'open'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="pos_close"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
  <input name="filled" placeholder="Filled by code" size="9"><input name="note" placeholder="or reason to close" size="14"><button class="btn sm ghost">Close</button></form><?php endif; ?></td></tr>
<?php endforeach; ?><?php if (!$all): ?><tr><td colspan="7"><em>No positions yet.</em></td></tr><?php endif; ?>
</tbody></table></section>

<?php else: $checks = org_checks($people, $positions, $today); $labels = ['nomgr' => 'No manager', 'unknown' => 'Manager not on master', 'mgrleft' => 'Manager has left', 'cycle' => 'Reporting circle', 'posleft' => 'Position under a leaver']; ?>
<section class="card"><h2>Checks · <?= count($checks) ?></h2>
<?php if (!$checks): ?><p class="pill on">Everything lines up.</p><?php else: ?><table><thead><tr><th>Check</th><th>Who</th><th></th></tr></thead><tbody>
<?php foreach ($checks as [$k, $code, $text]): ?><tr><td><span class="pill port"><?= h($labels[$k]) ?></span></td><td><?= h($text) ?></td>
<td><?php if (!str_starts_with($code, 'P') && ($e = emp_by_code($db, $code))): ?><a href="../employees/index.php?e=<?= (int)$e['id'] ?>">Open record</a><?php else: ?><a href="?v=pos">Positions</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></section>
<?php endif; ?>
<?php ui_foot();
